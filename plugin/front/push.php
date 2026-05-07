<?php
/**
 * Network connections push endpoint.
 *
 * Receives a JSON POST from glpi-agent's Connections.pm after each collection
 * cycle. The agent submits its standard inventory normally; connection data
 * is delivered separately to this endpoint to avoid GLPI 11 schema rejection
 * of custom inventory keys.
 *
 * Authentication: Bearer token in Authorization header. The token is fetched
 * by the agent from agentconfig.php at startup and rotated by admins via the
 * Collection Settings page.
 *
 * Payload format:
 * {
 *   "hostname": "SERVER01",
 *   "collected_at": "2026-05-07 14:30:00",
 *   "collection_method": "powershell" | "netstat",
 *   "connections": [
 *     { "protocol": "TCP", "local_addr": "...", "local_port": 0, ... },
 *     ...
 *   ],
 *   "listening": [
 *     { "protocol": "TCP", "local_addr": "...", "local_port": 0, ... },
 *     ...
 *   ]
 * }
 *
 * No GLPI session required — STRATEGY_NO_CHECK bypass registered in setup.php.
 */
include(__DIR__ . '/../../../inc/includes.php');

// STRATEGY_NO_CHECK skips plugin bootstrap. Force-load our plugin so its
// autoloader (registered in setup.php) becomes available. Connection class
// extends CommonDBTM and depends on GLPI core being fully loaded — using
// Plugin::load() ensures both core classes and our autoloader are ready.
Plugin::load('netstatconnections', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

// ── Method check ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, ['error' => 'method_not_allowed', 'message' => 'POST required']);
}

// ── Token authentication ─────────────────────────────────────────────────────
$auth   = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token  = '';
if (preg_match('/^Bearer\s+([A-Za-z0-9]+)/i', $auth, $m)) {
    $token = $m[1];
}
// Fallback: token in body (less ideal but works through proxies that strip headers)
$raw    = file_get_contents('php://input');
$body   = json_decode($raw, true);
if (!is_array($body)) {
    reply(400, ['error' => 'invalid_json']);
}
if ($token === '' && !empty($body['token'])) {
    $token = (string)$body['token'];
}

if (!PluginNetstatconnectionsAgentconfig::validateToken($token)) {
    reply(401, ['error' => 'unauthorized', 'message' => 'invalid or missing token']);
}

// ── Validate payload ─────────────────────────────────────────────────────────
$hostname = trim((string)($body['hostname'] ?? ''));
if ($hostname === '') {
    reply(400, ['error' => 'missing_hostname']);
}

$method      = (string)($body['collection_method'] ?? 'netstat');
$collected   = (string)($body['collected_at']      ?? date('Y-m-d H:i:s'));
$connections = is_array($body['connections'] ?? null) ? $body['connections'] : [];
$listening   = is_array($body['listening']   ?? null) ? $body['listening']   : [];

// ── Resolve Computer ID ──────────────────────────────────────────────────────
global $DB;
$row = $DB->request([
    'SELECT' => ['id'],
    'FROM'   => 'glpi_computers',
    'WHERE'  => ['name' => $hostname, 'is_deleted' => 0],
    'LIMIT'  => 1,
])->current();
$computers_id = (int)($row['id'] ?? 0);

if ($computers_id <= 0) {
    reply(404, [
        'error'    => 'computer_not_found',
        'hostname' => $hostname,
        'message'  => 'No computer with this name found in GLPI inventory',
    ]);
}

// ── Normalize connection rows ────────────────────────────────────────────────
$normalized = [];
foreach ($connections as $c) {
    if (!is_array($c)) {
        continue;
    }
    $normalized[] = [
        'protocol'          => (string)($c['protocol']        ?? 'TCP'),
        'local_addr'        => (string)($c['local_addr']      ?? ''),
        'local_port'        => (int)   ($c['local_port']      ?? 0),
        'remote_addr'       => (string)($c['remote_addr']     ?? ''),
        'remote_port'       => (int)   ($c['remote_port']     ?? 0),
        'remote_hostname'   => (string)($c['remote_hostname'] ?? ''),
        'state'             => (string)($c['state']           ?? ''),
        'conn_direction'    => (string)($c['conn_direction']  ?? ''),
        'service_port'      => (int)   ($c['service_port']    ?? 0),
        'process_name'      => (string)($c['process_name']    ?? ''),
        'service_name'      => (string)($c['service_name']    ?? ''),
        'created_at'        => (string)($c['created_at']      ?? ''),
        'offload_state'     => (string)($c['offload_state']   ?? ''),
        'applied_setting'   => (string)($c['applied_setting'] ?? ''),
        'collection_method' => $method,
    ];
}

// ── Persist ──────────────────────────────────────────────────────────────────
try {
    PluginNetstatconnectionsConnection::handleInventory(
        $computers_id,
        $normalized,
        $collected
    );
} catch (\Throwable $e) {
    reply(500, ['error' => 'persist_failed', 'message' => $e->getMessage()]);
}

reply(200, [
    'status'           => 'ok',
    'hostname'         => $hostname,
    'computers_id'     => $computers_id,
    'connections_count'=> count($normalized),
    'listening_count'  => count($listening),
    'collection_method'=> $method,
]);
