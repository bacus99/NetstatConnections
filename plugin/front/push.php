<?php
/**
 * Bulk push endpoint for netstat collector — v1.3.0
 * Boots GLPI's Symfony kernel to get $DB, then calls handleInventory().
 */

// ── Boot GLPI Kernel (same as public/index.php) ──────────────────────
$glpi_root = dirname(realpath(__FILE__) ?: __FILE__, 4);
if (!file_exists($glpi_root . '/vendor/autoload.php')) {
    $glpi_root = '/usr/share/glpi';
}

require_once $glpi_root . '/vendor/autoload.php';

use Glpi\Kernel\Kernel;
use Symfony\Component\HttpFoundation\Request;

$kernel = new Kernel();
$kernel->boot();

global $DB;

// If $DB is still null after boot, get it from the container
if ($DB === null) {
    try {
        $container = $kernel->getContainer();
        if ($container && $container->has('database')) {
            $DB = $container->get('database');
        }
    } catch (\Throwable $e) {}
}

// Last resort: direct instantiation
if ($DB === null) {
    try { $DB = new DBmysql(); } catch (\Throwable $e) {}
}

if ($DB === null) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'DB not available after kernel boot']);
    exit;
}

// Close kernel's session so we can resume the agent's session
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

ini_set('memory_limit', '256M');
set_time_limit(300);
header('Content-Type: application/json; charset=utf-8');

// ── Only accept POST ─────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed — use POST']);
    exit;
}

// ── Auth ─────────────────────────────────────────────────────────────
// Option A: X-NetStat-Token (GLPI Agent Perl module — no session needed)
$netstat_token = $_SERVER['HTTP_X_NETSTAT_TOKEN'] ?? '';

if (!empty($netstat_token)) {
    // Validate against push_token stored in plugin config table
    $valid_token = '';
    try {
        $cfg_row = $DB->request([
            'SELECT' => ['value'],
            'FROM'   => 'glpi_plugin_netstatconnections_config',
            'WHERE'  => ['key' => 'push_token'],
            'LIMIT'  => 1,
        ])->current();
        $valid_token = $cfg_row['value'] ?? '';
    } catch (\Throwable $e) {}

    if (empty($valid_token) || !hash_equals($valid_token, $netstat_token)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid X-NetStat-Token']);
        exit;
    }
    // Token is valid — skip session auth
    goto auth_ok;
}

// Option B: Session-Token (GLPI REST API session — legacy bat/script auth)
$session_token = $_SERVER['HTTP_SESSION_TOKEN'] ?? '';

if (empty($session_token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing authentication — provide X-NetStat-Token or Session-Token header']);
    exit;
}

// Resume the agent's REST API session
session_id($session_token);
session_start();

if (!isset($_SESSION['glpiID']) || empty($_SESSION['glpiID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired session']);
    exit;
}

auth_ok:

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
    echo json_encode(['error' => 'Payload must contain a connections array']);
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
    echo json_encode(['error' => 'Computer not found', 'hostname' => $hostname]);
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
    echo json_encode(['error' => 'handleInventory failed', 'message' => $e->getMessage()]);
    exit;
}

$elapsed = round((microtime(true) - $t_start) * 1000);

// ── Stats ────────────────────────────────────────────────────────────
$stats = ['active' => 0, 'closed' => 0, 'locked' => 0];
try {
    $stat_iter = $DB->request([
        'SELECT' => [
            'connection_status',
            'is_locked',
            new \Glpi\DBAL\QueryExpression('COUNT(*) AS cnt'),
        ],
        'FROM'     => 'glpi_plugin_netstatconnections_connections',
        'WHERE'    => ['computers_id' => $computers_id],
        'GROUPBY'  => ['connection_status', 'is_locked'],
    ]);
    foreach ($stat_iter as $s) {
        if ((int)$s['is_locked'] === 1) $stats['locked'] += (int)$s['cnt'];
        if ($s['connection_status'] === 'active') $stats['active'] += (int)$s['cnt'];
        else $stats['closed'] += (int)$s['cnt'];
    }
} catch (\Throwable $e) {}

echo json_encode([
    'status'       => 'ok',
    'version'      => '1.3.0',
    'computers_id' => $computers_id,
    'hostname'     => $hostname,
    'pushed'       => $conn_count,
    'elapsed_ms'   => $elapsed,
    'stats'        => $stats,
]);
