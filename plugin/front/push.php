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

$method      = (string)($body['collection_method'] ?? 'netstat');
$collected   = (string)($body['collected_at']      ?? date('Y-m-d H:i:s'));
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

// ── Persist connections ──────────────────────────────────────────────────
// Lifecycle pattern (from PluginNetstatconnectionsConnection::handleInventory):
//   1. Mark all existing rows for this computer as 'closed' with current ts as last_seen
//      base — we'll reset last_seen=now() for ones we still see.
//   2. For each incoming connection: UPSERT (insert or update last_seen).
//   3. Rows whose last_seen wasn't bumped are effectively closed.
//
// Simpler approach used here: REPLACE-style (delete+insert) for the active rows,
// preserving locked rows (is_locked=1) and respecting impact_direction.

$pdo->beginTransaction();
try {
    // Delete non-locked active rows for this computer that we'll replace
    $del = $pdo->prepare("DELETE FROM glpi_plugin_netstatconnections_connections
                          WHERE computers_id = ? AND is_locked = 0 AND connection_status = 'active'");
    $del->execute([$computers_id]);

    $ins = $pdo->prepare("INSERT INTO glpi_plugin_netstatconnections_connections
        (computers_id, protocol, local_addr, local_port, remote_addr, remote_port,
         remote_hostname, process_name, service_name, state, collected_at, last_seen,
         connection_status, created_at, conn_direction, service_port,
         collection_method, offload_state, applied_setting)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?)");

    $count = 0;
    foreach ($connections as $c) {
        if (!is_array($c)) continue;
        // Validate created_at to avoid '0000-00-00' warnings
        $created_at = null;
        if (!empty($c['created_at']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', (string)$c['created_at'])
            && !str_starts_with((string)$c['created_at'], '0000-')) {
            $created_at = (string)$c['created_at'];
        }
        $ins->execute([
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
            (string)($c['conn_direction']  ?? ''),
            (int)   ($c['service_port']    ?? 0),
            $method,
            (string)($c['offload_state']   ?? ''),
            (string)($c['applied_setting'] ?? ''),
        ]);
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
