<?php
/**
 * Bulk push endpoint — authenticates using GLPI REST API session.
 */

// Bootstrap GLPI without starting a web session
define('GLPI_ROOT', dirname(__DIR__, 3));
include_once(GLPI_ROOT . '/inc/includes.php');

ini_set('memory_limit', '256M');
set_time_limit(300);
header('Content-Type: application/json');

// Use the REST API's session validation
$session_token = $_SERVER['HTTP_SESSION_TOKEN'] ?? '';
$app_token     = $_SERVER['HTTP_APP_TOKEN'] ?? '';

if (empty($session_token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing Session-Token']);
    exit;
}

// Resume the API session (same mechanism GLPI REST API uses)
session_id($session_token);
session_start();

if (!isset($_SESSION['glpiID']) || empty($_SESSION['glpiID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired session']);
    exit;
}

// Read JSON body
$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data || !isset($data['connections'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

// Resolve hostname
$hostname = $data['hostname'] ?? '';
$computers_id = 0;

if (!empty($hostname)) {
    global $DB;
    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_computers',
        'WHERE'  => ['name' => $hostname, 'is_deleted' => 0],
        'LIMIT'  => 1,
    ])->current();
    if ($row) {
        $computers_id = (int)$row['id'];
    }
}

if ($computers_id === 0) {
    http_response_code(404);
    echo json_encode(['error' => "Computer not found: $hostname"]);
    exit;
}

$collected_at = $data['collected_at'] ?? date('Y-m-d H:i:s');

// Call merge logic
PluginNetstatconnectionsConnection::handleInventory(
    $computers_id,
    $data['connections'],
    $collected_at
);

echo json_encode([
    'status'       => 'ok',
    'computers_id' => $computers_id,
    'hostname'     => $hostname,
    'connections'  => count($data['connections']),
]);
