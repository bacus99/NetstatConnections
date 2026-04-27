<?php
/**
 * Bulk push endpoint for netstat collector — v1.3.2
 *
 * Auth: reuses the GLPI REST API session mechanism.
 *   1. Agent calls /apirest.php/initSession (gets session_token)
 *   2. Agent POSTs here with Session-Token + App-Token headers
 *   3. session_id() is set BEFORE the GLPI bootstrap so that GLPI's
 *      own session_start() resumes the correct session
 */

// Buffer all output so any accidental output from GLPI's bootstrap
// cannot corrupt our JSON response or cause "connection closed" errors.
ob_start();

// ── Auth headers — must be read before any output or bootstrap ───────
$session_token = $_SERVER['HTTP_SESSION_TOKEN'] ?? '';
$app_token     = $_SERVER['HTTP_APP_TOKEN']     ?? '';

if (empty($session_token)) {
    ob_end_clean();
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Missing Session-Token header']);
    exit;
}

// Set the session ID BEFORE GLPI bootstrap calls session_start()
session_id($session_token);

// ── Bootstrap GLPI ───────────────────────────────────────────────────
define('GLPI_ROOT', dirname(__DIR__, 3));
include_once(GLPI_ROOT . '/inc/includes.php');

// Discard any output the bootstrap may have produced, then take over
ob_end_clean();

ini_set('memory_limit', '256M');
set_time_limit(300);
header('Content-Type: application/json; charset=utf-8');

// ── Only accept POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed — use POST']);
    exit;
}

// ── Verify the session is valid ──────────────────────────────────────
if (!isset($_SESSION['glpiID']) || empty($_SESSION['glpiID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired session — call initSession first']);
    exit;
}

// ── Validate App-Token against GLPI's API client table ──────────────
if (!empty($app_token)) {
    global $DB;
    $api_client = $DB->request([
        'FROM'  => 'glpi_apiclients',
        'WHERE' => [
            'app_token' => $app_token,
            'is_active' => 1,
        ],
        'LIMIT' => 1,
    ])->current();
    if (!$api_client) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or inactive App-Token']);
        exit;
    }
}

// ── Read & validate payload ──────────────────────────────────────────
$body = file_get_contents('php://input');
if (empty($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
}

$data = json_decode($body, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

if (!isset($data['connections']) || !is_array($data['connections'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload must contain a "connections" array']);
    exit;
}

$hostname     = trim($data['hostname'] ?? '');
$collected_at = $data['collected_at'] ?? date('Y-m-d H:i:s');

// ── Resolve hostname → computers_id ──────────────────────────────────
$computers_id = 0;
if (!empty($hostname)) {
    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_computers',
        'WHERE'  => ['name' => $hostname, 'is_deleted' => 0],
        'ORDER'  => ['id ASC'],
        'LIMIT'  => 1,
    ])->current();
    if ($row) {
        $computers_id = (int)$row['id'];
    }
}

if ($computers_id === 0) {
    http_response_code(404);
    echo json_encode([
        'error'    => 'Computer not found',
        'hostname' => $hostname,
        'hint'     => 'Ensure this hostname matches a Computer name in GLPI (case-sensitive)',
    ]);
    exit;
}

// ── Call merge logic ─────────────────────────────────────────────────
$conn_count = count($data['connections']);
$t_start    = microtime(true);

try {
    PluginNetstatconnectionsConnection::handleInventory(
        $computers_id,
        $data['connections'],
        $collected_at
    );
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'        => 'handleInventory failed',
        'message'      => $e->getMessage(),
        'computers_id' => $computers_id,
    ]);
    exit;
}

$elapsed = round((microtime(true) - $t_start) * 1000);

// ── Count active/closed rows for stats ───────────────────────────────
$stats = ['active' => 0, 'closed' => 0, 'locked' => 0];
$stat_iter = $DB->request([
    'SELECT' => [
        'connection_status',
        'is_locked',
        new \Glpi\DBAL\QueryExpression('COUNT(*) AS cnt'),
    ],
    'FROM'   => 'glpi_plugin_netstatconnections_connections',
    'WHERE'  => ['computers_id' => $computers_id],
    'GROUPBY' => ['connection_status', 'is_locked'],
]);
foreach ($stat_iter as $s) {
    if ((int)$s['is_locked'] === 1) {
        $stats['locked'] += (int)$s['cnt'];
    }
    if ($s['connection_status'] === 'active') {
        $stats['active'] += (int)$s['cnt'];
    } else {
        $stats['closed'] += (int)$s['cnt'];
    }
}

echo json_encode([
    'status'       => 'ok',
    'version'      => '1.3.1',
    'computers_id' => $computers_id,
    'hostname'     => $hostname,
    'pushed'       => $conn_count,
    'elapsed_ms'   => $elapsed,
    'stats'        => $stats,
]);
