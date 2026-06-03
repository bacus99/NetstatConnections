<?php
/**
 * Network connections push endpoint — STANDALONE (no GLPI bootstrap dependency).
 *
 * GLPI 11's stateless inventory endpoints are unreliable: $DB is null on POST,
 * Plugin::load() needs constants that aren't set, etc. We sidestep all of that
 * by talking to MySQL directly via PDO using credentials read from
 * /etc/glpi/config_db.php (or $glpi/config/config_db.php).
 *
 * Auth: Bearer token validated against `glpi_plugin_netstatconnections_config`.
 * Apache often strips the Authorization header, so we accept token in body too.
 *
 * Payload:
 * {
 *   "hostname": "SERVER01",
 *   "collected_at": "2026-05-07 14:30:00",
 *   "collection_method": "powershell",
 *   "connections": [...],
 *   "listening": [...],
 *   "token": "<bearer token>"   // optional fallback
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

// ── Method check ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, ['error' => 'method_not_allowed', 'message' => 'POST required']);
}

// ── Locate config_db.php and parse credentials ───────────────────────────
// Try GLPI 11 path (/etc/glpi11) first, then GLPI 10 (/etc/glpi), then in-tree.
$config_path = null;
foreach ([
    '/etc/glpi11/config_db.php',
    '/etc/glpi/config_db.php',
    __DIR__ . '/../../../config/config_db.php',
] as $p) {
    if (file_exists($p)) { $config_path = $p; break; }
}
if (!$config_path) {
    reply(500, ['error' => 'config_missing']);
}

// Parse the DB class definition with regex (avoid extending DBmysql which needs full GLPI bootstrap)
$contents = file_get_contents($config_path);
$cred = [];
foreach (['dbhost', 'dbuser', 'dbpassword', 'dbdefault'] as $key) {
    if (preg_match('/\$' . $key . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $contents, $m)) {
        $cred[$key] = $m[1];
    }
}
if (!isset($cred['dbhost'], $cred['dbuser'], $cred['dbpassword'], $cred['dbdefault'])) {
    reply(500, ['error' => 'config_parse_failed', 'found' => array_keys($cred)]);
}

// ── Connect via PDO ──────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host={$cred['dbhost']};dbname={$cred['dbdefault']};charset=utf8mb4",
        $cred['dbuser'],
        $cred['dbpassword'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (\Throwable $e) {
    reply(500, ['error' => 'db_connect_failed', 'message' => $e->getMessage()]);
}

// ── Read body (try Symfony Request + php://input) ────────────────────────
$raw = '';
if (class_exists('\Symfony\Component\HttpFoundation\Request')) {
    try {
        $sf_req = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
        $raw = $sf_req->getContent();
    } catch (\Throwable $e) {}
}
if ($raw === '') {
    $raw = file_get_contents('php://input');
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    reply(400, ['error' => 'invalid_json', 'raw_len' => strlen($raw)]);
}

// ── Token authentication ─────────────────────────────────────────────────
$token = '';
$auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+([A-Za-z0-9]+)/i', $auth, $m)) {
    $token = $m[1];
}
if ($token === '' && !empty($body['token'])) {
    $token = (string)$body['token'];
}

$stmt = $pdo->prepare("SELECT value FROM glpi_plugin_netstatconnections_config WHERE `key` = 'push_token' LIMIT 1");
$stmt->execute();
$valid = (string)($stmt->fetchColumn() ?: '');

if ($valid === '' || $token === '' || !hash_equals($valid, $token)) {
    reply(401, ['error' => 'unauthorized', 'message' => 'invalid or missing token']);
}

// ── Resolve Computer ID ──────────────────────────────────────────────────
$hostname = trim((string)($body['hostname'] ?? ''));
if ($hostname === '') {
    reply(400, ['error' => 'missing_hostname']);
}

$stmt = $pdo->prepare("SELECT id FROM glpi_computers WHERE name = ? AND is_deleted = 0 LIMIT 1");
$stmt->execute([$hostname]);
$computers_id = (int)($stmt->fetchColumn() ?: 0);

if ($computers_id <= 0) {
    reply(404, [
        'error'    => 'computer_not_found',
        'hostname' => $hostname,
        'message'  => 'No computer with this name found in GLPI inventory',
    ]);
}

$method = (string)($body['collection_method'] ?? 'netstat');

/**
 * Normalize any reasonable datetime string the agent sends into MySQL's
 * canonical 'Y-m-d H:i:s' format. Returns null for invalid / empty input
 * so callers can decide on a fallback.
 *
 * Handles:
 *   - 'YYYY-MM-DD HH:MM:SS'           (already canonical)
 *   - 'YYYY-MM-DD H:MM:SS AM/PM'      (PowerShell Get-Date default in en-US)
 *   - 'M/D/YYYY H:MM:SS AM/PM'        (other PowerShell culture variations)
 *   - ISO-8601 'YYYY-MM-DDTHH:MM:SS'
 *
 * Rejects:
 *   - empty string
 *   - '0000-00-00 00:00:00' (zero-date, invalid for MySQL TIMESTAMP)
 *   - anything strtotime() can't parse
 */
function normalize_datetime(?string $val): ?string {
    if ($val === null) return null;
    $val = trim($val);
    if ($val === '') return null;
    if (str_starts_with($val, '0000-')) return null;

    $ts = strtotime($val);
    if ($ts === false || $ts <= 0) return null;

    return date('Y-m-d H:i:s', $ts);
}

// $collected must be a valid datetime — fall back to NOW() if absent or invalid.
$collected = normalize_datetime((string)($body['collected_at'] ?? ''))
          ?? date('Y-m-d H:i:s');

/**
 * Stable identity for a dependency "edge", independent of the ephemeral port.
 * MUST stay byte-for-byte identical to the SHA1(CONCAT_WS('|', ...)) formula in
 * hook.php's backfill, or accumulated history won't match new pushes.
 *
 *   outbound: peer = remote_addr, service = remote_port
 *   inbound:  peer = remote_addr, service = local_port
 *
 * Ephemeral ports are deliberately excluded so the 500 ephemeral source sockets
 * of one host→DB dependency collapse to ONE edge whose seen_count accumulates.
 */
function edge_key_for(int $computers_id, string $proto, string $dir, int $svc, string $raddr, string $pname): string {
    return sha1(
        $computers_id . '|' . strtoupper($proto) . '|' . $dir . '|'
        . $svc . '|' . strtolower($raddr) . '|' . strtolower($pname)
    );
}

/** Derive (direction, service_port) the same way the migration backfill does. */
function derive_edge(array $c): array {
    $rport = (int)($c['remote_port'] ?? 0);
    $lport = (int)($c['local_port']  ?? 0);
    $dir   = (string)($c['conn_direction'] ?? '');
    if ($dir === '') {
        $dir = ($rport >= 49152 && $lport < 49152) ? 'inbound' : 'outbound';
    }
    $svc = (int)($c['service_port'] ?? 0);
    if ($svc === 0) {
        $svc = ($dir === 'inbound') ? $lport : $rport;
    }
    return [$dir, $svc];
}

$connections = is_array($body['connections'] ?? null) ? $body['connections'] : [];

// ── Apply server-side filters (from agent_collection config) ─────────────
// This way: admin changes filters in UI → take effect immediately for next push.
// No agent redeploy needed.
$cfg_stmt = $pdo->prepare("SELECT value FROM glpi_plugin_netstatconnections_config WHERE `key` = 'agent_collection' LIMIT 1");
$cfg_stmt->execute();
$cfg_json = $cfg_stmt->fetchColumn();
$server_cfg = $cfg_json ? json_decode($cfg_json, true) : [];
if (!is_array($server_cfg)) { $server_cfg = []; }

$exclude_processes    = array_map('strtolower', $server_cfg['exclude_processes']    ?? []);
$exclude_remote_ips   = $server_cfg['exclude_remote_ips']   ?? [];
$exclude_remote_ports = array_map('intval', $server_cfg['exclude_remote_ports'] ?? []);
$include_only_ips     = $server_cfg['include_only_ips']     ?? [];
$skip_loopback        = !empty($server_cfg['skip_loopback']);
$skip_ipv6            = !empty($server_cfg['skip_ipv6']);
$ephemeral_threshold  = (int)($server_cfg['ephemeral_port_threshold'] ?? 0);
$established_only     = !empty($server_cfg['established_only']);

$is_loopback = static function (string $ip): bool {
    if ($ip === '' || $ip === '0.0.0.0' || $ip === '::') return false;
    if (str_starts_with($ip, '127.')) return true;
    if ($ip === '::1') return true;
    return false;
};
$is_ipv6 = static fn(string $ip): bool => str_contains($ip, ':');

$filtered = [];
$skipped  = ['process' => 0, 'remote_ip' => 0, 'remote_port' => 0, 'include_only' => 0, 'loopback' => 0, 'ipv6' => 0, 'ephemeral' => 0, 'state' => 0];
foreach ($connections as $c) {
    if (!is_array($c)) continue;
    $proc  = strtolower((string)($c['process_name'] ?? ''));
    $raddr = (string)($c['remote_addr'] ?? '');
    $laddr = (string)($c['local_addr']  ?? '');
    $rport = (int)   ($c['remote_port'] ?? 0);
    $state = strtoupper((string)($c['state'] ?? ''));

    if (in_array($proc, $exclude_processes, true))                    { $skipped['process']++;     continue; }
    if (in_array($raddr, $exclude_remote_ips, true))                  { $skipped['remote_ip']++;   continue; }
    if (in_array($rport, $exclude_remote_ports, true))                { $skipped['remote_port']++; continue; }
    if (!empty($include_only_ips) && !in_array($raddr, $include_only_ips, true)) { $skipped['include_only']++; continue; }
    if ($skip_loopback && ($is_loopback($raddr) || $is_loopback($laddr))) { $skipped['loopback']++; continue; }
    if ($skip_ipv6 && ($is_ipv6($raddr) || $is_ipv6($laddr)))          { $skipped['ipv6']++;        continue; }
    // Ephemeral filter: drop ONLY when BOTH ports are ephemeral (peer-to-peer noise).
    // Inbound (local=service, remote=ephemeral) and outbound (local=ephemeral, remote=service)
    // must both be kept. Otherwise we'd lose every inbound MSSQL/HTTPS/etc connection.
    $lport = (int)($c['local_port'] ?? 0);
    if ($ephemeral_threshold > 0
        && $rport >= $ephemeral_threshold
        && $lport >= $ephemeral_threshold) {
        $skipped['ephemeral']++; continue;
    }
    if ($established_only && $state !== '' && !in_array($state, ['ESTABLISHED', 'CLOSE_WAIT', 'TIME_WAIT'], true)) {
        $skipped['state']++; continue;
    }

    $filtered[] = $c;
}
$connections = $filtered;

// ── Persist connections — accumulating edge ledger (v2.4.0) ───────────────
// We NO LONGER delete-and-replace. Instead each push ACCUMULATES observations:
//
//   1. Collapse the push's raw connections into unique EDGES (by edge_key),
//      counting how many raw sockets map to each edge this push (conn_count).
//   2. For each edge: UPSERT — if it already exists for this computer, bump
//      last_seen + seen_count (the consistency/weight signal) and refresh the
//      volatile fields; otherwise INSERT it with seen_count=1, first_seen=now.
//   3. Edges NOT in this push are left untouched — they keep their last_seen and
//      get marked 'closed' by the lifecycle cron once stale. This is the whole
//      point: we retain the long tail (the 3am batch job's DB connection that a
//      periodic agentless scan would miss) instead of wiping it every push.
//
// Locked rows are handled by the SAME upsert (is_locked / impact_direction /
// resolution columns are never overwritten), so no separate locked-row pass.
//
// Matching is by (computers_id, edge_key) with a non-unique index — no UNIQUE
// constraint, so no risky one-shot dedupe of existing prod rows is required.
// Any pre-2.4.0 duplicate raw rows for an edge simply go stale and are purged
// by the cleanup cron; the UI GROUP BY collapses them in the meantime.

// Collapse raw connections → edges
$edges = [];   // edge_key => ['c' => representative conn, 'count' => raw sockets this push]
foreach ($connections as $c) {
    if (!is_array($c)) continue;
    [$dir, $svc] = derive_edge($c);
    $ek = edge_key_for(
        $computers_id,
        (string)($c['protocol'] ?? 'TCP'),
        $dir, $svc,
        (string)($c['remote_addr'] ?? ''),
        (string)($c['process_name'] ?? '')
    );
    if (!isset($edges[$ek])) {
        $c['_dir'] = $dir;
        $c['_svc'] = $svc;
        $edges[$ek] = ['c' => $c, 'count' => 0];
    }
    $edges[$ek]['count']++;
}

$pdo->beginTransaction();
try {
    // Find an existing row for this edge (prefer a locked one, then most recent).
    $sel = $pdo->prepare("SELECT id FROM glpi_plugin_netstatconnections_connections
        WHERE computers_id = ? AND edge_key = ?
        ORDER BY is_locked DESC, last_seen DESC, id DESC LIMIT 1");

    // INSERT a brand-new edge.
    $ins = $pdo->prepare("INSERT INTO glpi_plugin_netstatconnections_connections
        (edge_key, computers_id, protocol, local_addr, local_port, remote_addr, remote_port,
         remote_hostname, process_name, service_name, state, collected_at, last_seen,
         connection_status, created_at, first_seen, seen_count, conn_count,
         conn_direction, service_port, collection_method, offload_state, applied_setting,
         process_pid, process_started, session_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // UPDATE an existing edge — bump observation counters + refresh volatile fields.
    // Never touches: is_locked, impact_direction, first_seen, created_at,
    // remote_items_id, remote_itemtype, remote_scope, resolved_via, resolved_at.
    // reopened_at is stamped FIRST so its IF() reads the PRE-update
    // connection_status: a closed→active transition (a dependency coming back)
    // gets NOW(); a normal push of an already-active edge keeps the old value.
    // MySQL evaluates SET assignments left-to-right and later refs see earlier
    // assignments, so this MUST precede `connection_status = 'active'`.
    $upd = $pdo->prepare("UPDATE glpi_plugin_netstatconnections_connections
        SET reopened_at = IF(connection_status = 'closed', NOW(), reopened_at),
            last_seen = ?, collected_at = ?, connection_status = 'active', closed_at = NULL,
            seen_count = seen_count + 1, conn_count = ?,
            state = ?, remote_hostname = ?, local_addr = ?, local_port = ?,
            remote_port = ?, service_name = ?, collection_method = ?,
            offload_state = ?, applied_setting = ?,
            process_pid = ?, process_started = ?, session_id = ?
        WHERE id = ?");

    $count = 0;
    foreach ($edges as $ek => $edge) {
        $c          = $edge['c'];
        $raw_count  = $edge['count'];
        $created_at = normalize_datetime((string)($c['created_at'] ?? '')) ?? $collected;
        $pstarted   = normalize_datetime((string)($c['process_started'] ?? ''));
        $ppid       = ((int)($c['pid'] ?? 0)) ?: null;
        $psession   = (isset($c['session_id']) && $c['session_id'] !== '') ? (int)$c['session_id'] : null;

        $sel->execute([$computers_id, $ek]);
        $existing_id = (int)($sel->fetchColumn() ?: 0);

        if ($existing_id > 0) {
            $upd->execute([
                $collected, $collected, $raw_count,
                (string)($c['state']           ?? ''),
                (string)($c['remote_hostname'] ?? ''),
                (string)($c['local_addr']      ?? ''),
                (int)   ($c['local_port']      ?? 0),
                (int)   ($c['remote_port']     ?? 0),
                (string)($c['service_name']    ?? ''),
                $method,
                (string)($c['offload_state']   ?? ''),
                (string)($c['applied_setting'] ?? ''),
                $ppid, $pstarted, $psession,
                $existing_id,
            ]);
        } else {
            $ins->execute([
                $ek,
                $computers_id,
                (string)($c['protocol']        ?? 'TCP'),
                (string)($c['local_addr']      ?? ''),
                (int)   ($c['local_port']      ?? 0),
                (string)($c['remote_addr']     ?? ''),
                (int)   ($c['remote_port']     ?? 0),
                (string)($c['remote_hostname'] ?? ''),
                (string)($c['process_name']    ?? ''),
                (string)($c['service_name']    ?? ''),
                (string)($c['state']           ?? ''),
                $collected,
                $collected,
                $created_at,
                $collected,          // first_seen
                $raw_count,          // conn_count
                (string)($c['_dir'] ?? ''),
                (int)   ($c['_svc'] ?? 0),
                $method,
                (string)($c['offload_state']   ?? ''),
                (string)($c['applied_setting'] ?? ''),
                $ppid, $pstarted, $psession,
            ]);
        }
        $count++;
    }

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    reply(500, ['error' => 'persist_failed', 'message' => $e->getMessage()]);
}

reply(200, [
    'status'            => 'ok',
    'hostname'          => $hostname,
    'computers_id'      => $computers_id,
    'connections_count' => $count,
    'skipped'           => $skipped,
    'collection_method' => $method,
]);
