<?php
/**
 * lock.php — AJAX endpoint for lock/unlock + impact direction toggle
 *
 * v1.1.2:
 *   - Lock by IP only (removed remote_port from WHERE) — all ports from same IP lock together
 *   - Bidirectional _removeImpactRelation before creating new one
 *   - Cluster resolution support
 */

include('../../../inc/includes.php');

header('Content-Type: application/json');

Session::checkLoginUser();

global $DB;

$id            = (int)($_GET['id'] ?? 0);
$locked        = (int)($_GET['locked'] ?? 0);
$computers_id  = (int)($_GET['computers_id'] ?? 0);
$direction     = $_GET['direction'] ?? null;

// Auto-detect direction on first lock based on conn_direction
// (will be overridden below once we have the row)
$change_dir    = (int)($_GET['change_direction'] ?? 0);
$remote_addr   = $_GET['remote_addr'] ?? '';
$ajax          = (int)($_GET['ajax'] ?? 0);

if ($id <= 0 || $computers_id <= 0) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

// Get the connection row
$conn = $DB->request([
    'FROM'  => 'glpi_plugin_netstatconnections_connections',
    'WHERE' => ['id' => $id],
])->current();

if (!$conn) {
    // Try lookup by remote_addr (IP-only lock)
    if (!empty($remote_addr)) {
        $conn = $DB->request([
            'FROM'  => 'glpi_plugin_netstatconnections_connections',
            'WHERE' => [
                'computers_id' => $computers_id,
                'remote_addr'  => $remote_addr,
            ],
            'LIMIT' => 1,
        ])->current();
    }
}

if (!$conn) {
    echo json_encode(['error' => 'Connection not found']);
    exit;
}

// Auto-set direction from conn_direction if not explicitly passed
if ($direction === null) {
    $connDir = $conn['conn_direction'] ?? 'outbound';
    $direction = ($connDir === 'inbound') ? 'depends' : 'impacts';
}

// ── Build WHERE for all rows with same remote IP on this computer ──
$where = [
    'computers_id'  => $computers_id,
    'remote_addr'   => $conn['remote_addr'],
];

// ── Determine service port + direction ──────────────────────────────
$connDir = $conn['conn_direction'] ?? 'outbound';
if ($connDir === '' || $connDir === null) {
    // Fallback detection from port numbers
    if (($conn['remote_port'] ?? 0) >= 49152 && ($conn['local_port'] ?? 0) < 49152) {
        $connDir = 'inbound';
    } else {
        $connDir = 'outbound';
    }
}

$svcPort = ($connDir === 'inbound')
    ? (int)($conn['local_port'] ?? 0)
    : (int)($conn['remote_port'] ?? 0);
if ($svcPort === 0) {
    $svcPort = min((int)($conn['local_port'] ?? 65535), (int)($conn['remote_port'] ?? 65535));
}

// ── Lock / Unlock ────────────────────────────────────────────────────

$update = ['is_locked' => $locked];
if ($locked) {
    $update['impact_direction'] = $direction;
    $update['service_port']     = $svcPort;
}

$DB->update('glpi_plugin_netstatconnections_connections', $update, $where);

$port_label = PluginNetstatconnectionsPort::getBadgeLabel($svcPort, $conn['protocol'] ?? 'TCP');

// ── Resolve remote CI ────────────────────────────────────────────────

$remote_items_id = (int)($conn['remote_items_id'] ?? 0);
$remote_itemtype = $conn['remote_itemtype'] ?? '';

if ($locked && ($remote_items_id === 0 || empty($remote_itemtype))) {
    // Try sibling row
    $sibling = $DB->request([
        'SELECT' => ['remote_items_id', 'remote_itemtype'],
        'FROM'   => 'glpi_plugin_netstatconnections_connections',
        'WHERE'  => [
            'computers_id'    => $computers_id,
            'remote_addr'     => $conn['remote_addr'],
            ['NOT' => ['remote_items_id' => null]],
            ['NOT' => ['remote_items_id' => 0]],
        ],
        'LIMIT'  => 1,
    ])->current();

    if ($sibling) {
        $remote_items_id = (int)$sibling['remote_items_id'];
        $remote_itemtype = $sibling['remote_itemtype'];
    } else {
        // Full resolver
        $resolved = PluginNetstatconnectionsResolver::resolveIP($conn['remote_addr']);
        if ($resolved['remote_items_id']) {
            $remote_items_id = (int)$resolved['remote_items_id'];
            $remote_itemtype = $resolved['remote_itemtype'];
            // Persist resolution on all rows for this IP
            $DB->update('glpi_plugin_netstatconnections_connections', [
                'remote_items_id' => $remote_items_id,
                'remote_itemtype' => $remote_itemtype,
                'remote_scope'    => $resolved['remote_scope'],
                'resolved_via'    => 'lock',
                'resolved_at'     => new \Glpi\DBAL\QueryExpression('NOW()'),
            ], $where);
        }
    }
}

// ── Impact relations ─────────────────────────────────────────────────

if ($locked && $remote_items_id > 0 && $remote_items_id !== $computers_id) {

    // ── Pillar 2: Check if this is a database port → resolve to DatabaseInstance
    $impact_target_type = $remote_itemtype;
    $impact_target_id   = $remote_items_id;
    $chain_host_type    = '';
    $chain_host_id      = 0;

    // Check if the port is flagged as a database port (column may not exist on older installs)
    $is_db_port = 0;
    try {
        $port_def = $DB->request([
            'SELECT' => ['is_database_port'],
            'FROM'   => 'glpi_plugin_netstatconnections_ports',
            'WHERE'  => ['port_number' => $svcPort, 'protocol' => strtoupper($conn['protocol'] ?? 'TCP')],
            'LIMIT'  => 1,
        ])->current();
        $is_db_port = (int)($port_def['is_database_port'] ?? 0);
    } catch (\Throwable $e) {}

    if ($is_db_port && class_exists('PluginNetstatconnectionsResolver')) {
        $instance = PluginNetstatconnectionsResolver::resolveToInstance(
            $remote_itemtype, $remote_items_id, $svcPort
        );
        if ($instance) {
            $impact_target_type = 'DatabaseInstance';
            $impact_target_id   = (int)$instance['id'];

            // Pillar 3: chain DatabaseInstance → host (Computer or Cluster)
            $chain = PluginNetstatconnectionsResolver::resolveInstanceChain((int)$instance['id']);
            if (!empty($chain) && $chain['host_id'] > 0 && !empty($chain['host_type'])) {
                $chain_host_type = $chain['host_type'];
                $chain_host_id   = $chain['host_id'];
            }

            // Persist on connection row (resolved_via may not be in ENUM on older installs)
            try {
                $DB->update('glpi_plugin_netstatconnections_connections', [
                    'remote_items_id' => $impact_target_id,
                    'remote_itemtype' => $impact_target_type,
                    'resolved_via'    => 'db_instance',
                ], $where);
            } catch (\Throwable $e) {
                $DB->update('glpi_plugin_netstatconnections_connections', [
                    'remote_items_id' => $impact_target_id,
                    'remote_itemtype' => $impact_target_type,
                ], $where);
            }
        }
    }

    // Determine source → impacted based on direction
    if ($direction === 'impacts') {
        $src_type = 'Computer';          $src_id = $computers_id;
        $dst_type = $impact_target_type; $dst_id = $impact_target_id;
    } else {
        $src_type = $impact_target_type; $src_id = $impact_target_id;
        $dst_type = 'Computer';          $dst_id = $computers_id;
    }

    // Clean old relations
    _removeImpactRelation($src_type, $src_id, $dst_type, $dst_id);
    _removeImpactRelation($dst_type, $dst_id, $src_type, $src_id);

    // Create the correct direction
    _ensureImpactRelation($src_type, $src_id, $dst_type, $dst_id, $port_label);

    // Pillar 3: chain — DatabaseInstance → Cluster
    if ($chain_host_id > 0 && !empty($chain_host_type)) {
        _ensureImpactRelation(
            'DatabaseInstance', $impact_target_id,
            $chain_host_type, $chain_host_id,
            $port_label . ' (host)'
        );
    }

} elseif (!$locked) {
    // Unlock — remove impact relations (check both Computer and DatabaseInstance)
    _removeImpactRelation('Computer', $computers_id, $remote_itemtype ?: 'Computer', $remote_items_id);
    _removeImpactRelation($remote_itemtype ?: 'Computer', $remote_items_id, 'Computer', $computers_id);
    // Also clean any DatabaseInstance relations
    _removeImpactRelation('Computer', $computers_id, 'DatabaseInstance', $remote_items_id);
    _removeImpactRelation('DatabaseInstance', $remote_items_id, 'Computer', $computers_id);
}

echo json_encode([
    'success'        => true,
    'locked'         => $locked,
    'direction'      => $direction,
    'conn_direction' => $connDir,
    'service_port'   => $svcPort,
    'remote_type'    => $remote_itemtype,
    'remote_id'      => $remote_items_id,
]);

// ── Helper functions ─────────────────────────────────────────────────

function _ensureImpactRelation(string $src_type, int $src_id, string $dst_type, int $dst_id, string $name): void {
    global $DB;
    if ($src_id <= 0 || $dst_id <= 0) return;

    $exists = $DB->request([
        'FROM'  => 'glpi_impactrelations',
        'WHERE' => [
            'itemtype_source'   => $src_type, 'items_id_source'   => $src_id,
            'itemtype_impacted' => $dst_type, 'items_id_impacted' => $dst_id,
        ],
        'LIMIT' => 1,
    ])->current();

    if (!$exists) {
        $DB->insert('glpi_impactrelations', [
            'itemtype_source'   => $src_type,
            'items_id_source'   => $src_id,
            'itemtype_impacted' => $dst_type,
            'items_id_impacted' => $dst_id,
            'name'              => $name,
        ]);
    }
}

function _removeImpactRelation(string $src_type, int $src_id, string $dst_type, int $dst_id): void {
    global $DB;
    if ($src_id <= 0 || $dst_id <= 0) return;

    $DB->delete('glpi_impactrelations', [
        'itemtype_source'   => $src_type, 'items_id_source'   => $src_id,
        'itemtype_impacted' => $dst_type, 'items_id_impacted' => $dst_id,
    ]);
}
