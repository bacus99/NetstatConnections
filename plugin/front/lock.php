<?php
/**
 * lock.php — AJAX endpoint for lock/unlock + impact direction toggle
 *
 * v1.3.3 — GLPI 11.0.7 compatibility:
 *   - Resolves symlinks (public/plugins → ../plugins) before include path math
 *     so we always reach <glpi_root>/inc/includes.php no matter how Apache
 *     routes the URL.
 *
 * v1.3.2 — Per-port locking:
 *   - WHERE scoped to computers_id + remote_addr + service port (not whole IP)
 *   - Unlock only removes impact relation when NO other locked ports remain to same remote CI
 */

require_once __DIR__ . '/../inc/_bootstrap.php';

header('Content-Type: application/json');

Session::checkLoginUser();

global $DB;

$id            = (int)($_GET['id'] ?? 0);
$locked        = (int)($_GET['locked'] ?? 0);
$computers_id  = (int)($_GET['computers_id'] ?? 0);
$direction     = $_GET['direction'] ?? null;
$change_dir    = (int)($_GET['change_direction'] ?? 0);
$remote_addr   = $_GET['remote_addr'] ?? '';
$ajax          = (int)($_GET['ajax'] ?? 0);

if ($id <= 0 || $computers_id <= 0) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

// ── Fetch the connection row ──────────────────────────────────────────
$conn = $DB->request([
    'FROM'  => 'glpi_plugin_netstatconnections_connections',
    'WHERE' => ['id' => $id],
])->current();

if (!$conn && !empty($remote_addr)) {
    $conn = $DB->request([
        'FROM'  => 'glpi_plugin_netstatconnections_connections',
        'WHERE' => ['computers_id' => $computers_id, 'remote_addr' => $remote_addr],
        'LIMIT' => 1,
    ])->current();
}

if (!$conn) {
    echo json_encode(['error' => 'Connection not found']);
    exit;
}

// ── Determine direction + service port ───────────────────────────────
$connDir = $conn['conn_direction'] ?? 'outbound';
if ($connDir === '' || $connDir === null) {
    $connDir = (($conn['remote_port'] ?? 0) >= 49152 && ($conn['local_port'] ?? 0) < 49152)
        ? 'inbound' : 'outbound';
}

if ($direction === null) {
    // Service impacts its consumers: outbound = we consume the remote service
    // → 'depends' (it impacts us); inbound = remote clients consume OUR
    // service → 'impacts' (we impact them).
    $direction = ($connDir === 'inbound') ? 'impacts' : 'depends';
}

$svcPort = ($connDir === 'inbound')
    ? (int)($conn['local_port']  ?? 0)
    : (int)($conn['remote_port'] ?? 0);
if ($svcPort === 0) {
    $svcPort = min((int)($conn['local_port'] ?? 65535), (int)($conn['remote_port'] ?? 65535));
}

// ── WHERE: per-port scope (remote_addr + the service port column) ─────
// This lets HTTP 80 and LDAP 389 from the same host be locked/unlocked independently.
$where = [
    'computers_id' => $computers_id,
    'remote_addr'  => $conn['remote_addr'],
];
if ($connDir === 'inbound') {
    $where['local_port']  = (int)($conn['local_port']  ?? 0);
} else {
    $where['remote_port'] = (int)($conn['remote_port'] ?? 0);
}

// ── Lock / Unlock ─────────────────────────────────────────────────────
$update = ['is_locked' => $locked];
if ($locked) {
    $update['impact_direction'] = $direction;
    $update['service_port']     = $svcPort;
}
$DB->update('glpi_plugin_netstatconnections_connections', $update, $where);

$port_label = PluginNetstatconnectionsPort::getBadgeLabel($svcPort, $conn['protocol'] ?? 'TCP');

// ── Resolve remote CI ─────────────────────────────────────────────────
$remote_items_id = (int)($conn['remote_items_id'] ?? 0);
$remote_itemtype = $conn['remote_itemtype'] ?? '';

if ($locked && ($remote_items_id === 0 || empty($remote_itemtype))) {
    // Try any sibling row for this IP that already has a resolution
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
        $resolved = PluginNetstatconnectionsResolver::resolveIP($conn['remote_addr']);
        if ($resolved['remote_items_id']) {
            $remote_items_id = (int)$resolved['remote_items_id'];
            $remote_itemtype = $resolved['remote_itemtype'];
            // Persist resolution for all rows with this IP
            $DB->update('glpi_plugin_netstatconnections_connections', [
                'remote_items_id' => $remote_items_id,
                'remote_itemtype' => $remote_itemtype,
                'remote_scope'    => $resolved['remote_scope'],
                'resolved_via'    => 'lock',
                'resolved_at'     => new \Glpi\DBAL\QueryExpression('NOW()'),
            ], ['computers_id' => $computers_id, 'remote_addr' => $conn['remote_addr']]);
        }
    }
}

// ── Impact relations ──────────────────────────────────────────────────

if ($locked && $remote_items_id > 0 && $remote_items_id !== $computers_id) {

    $impact_target_type = $remote_itemtype;
    $impact_target_id   = $remote_items_id;
    $local_edge_type    = 'Computer';
    $local_edge_id      = $computers_id;
    $chain_host_type    = '';
    $chain_host_id      = 0;
    $chain_instance_id  = 0;

    // Pillar 2: database port → route the DATABASE-side endpoint through its
    // DatabaseInstance. Direction-aware: outbound = the remote hosts the DB;
    // inbound = the DB lives on THIS computer (the remote is just a client).
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

    if ($is_db_port && $connDir !== 'inbound' && class_exists('PluginNetstatconnectionsResolver')) {
        if ($remote_itemtype === 'DatabaseInstance') {
            // Already routed to an instance — just recover the host chain.
            $chain_instance_id = $remote_items_id;
            $chain = PluginNetstatconnectionsResolver::resolveInstanceChain($remote_items_id);
            if (!empty($chain) && $chain['host_id'] > 0 && !empty($chain['host_type'])) {
                $chain_host_type = $chain['host_type'];
                $chain_host_id   = $chain['host_id'];
            }
        } else {
            $instance = PluginNetstatconnectionsResolver::resolveToInstance(
                $remote_itemtype, $remote_items_id, $svcPort
            );
            if ($instance) {
                $impact_target_type = 'DatabaseInstance';
                $impact_target_id   = (int)$instance['id'];
                $chain_instance_id  = $impact_target_id;

                $chain = PluginNetstatconnectionsResolver::resolveInstanceChain((int)$instance['id']);
                if (!empty($chain) && $chain['host_id'] > 0 && !empty($chain['host_type'])) {
                    $chain_host_type = $chain['host_type'];
                    $chain_host_id   = $chain['host_id'];
                }

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
    } elseif ($is_db_port && $connDir === 'inbound' && class_exists('PluginNetstatconnectionsResolver')) {
        // Inbound: substitute the LOCAL endpoint with the local DatabaseInstance
        // so client edges attach to the instance, never the bare host.
        $local_inst = PluginNetstatconnectionsResolver::resolveToInstance(
            'Computer', $computers_id, $svcPort
        );
        if ($local_inst) {
            $local_edge_type   = 'DatabaseInstance';
            $local_edge_id     = (int)$local_inst['id'];
            $chain_instance_id = $local_edge_id;
            $chain_host_type   = 'Computer';
            $chain_host_id     = $computers_id;
        }
    }

    if ($direction === 'impacts') {
        $src_type = $local_edge_type;    $src_id = $local_edge_id;
        $dst_type = $impact_target_type; $dst_id = $impact_target_id;
    } else {
        $src_type = $impact_target_type; $src_id = $impact_target_id;
        $dst_type = $local_edge_type;    $dst_id = $local_edge_id;
    }

    // When the LOCAL endpoint routed through its instance, drop the legacy
    // direct host↔client edges so the path collapses through the instance.
    if ($local_edge_type === 'DatabaseInstance') {
        _removeImpactRelation('Computer', $computers_id, $impact_target_type, $impact_target_id);
        _removeImpactRelation($impact_target_type, $impact_target_id, 'Computer', $computers_id);
    }

    // Rebuild the name from ALL currently-locked ports to this CI
    // (the current port is already is_locked=1 in the DB at this point)
    $accumulated_name = _buildImpactName($computers_id, $impact_target_type, $impact_target_id);
    if ($accumulated_name === '') $accumulated_name = $port_label;

    // Only remove the wrong-direction relation (handles impacts↔depends toggle).
    // Do NOT remove the forward direction — that would wipe the accumulated name.
    _removeImpactRelation($dst_type, $dst_id, $src_type, $src_id);
    _setImpactRelation($src_type, $src_id, $dst_type, $dst_id, $accumulated_name);

    if ($chain_host_id > 0 && !empty($chain_host_type) && $chain_instance_id > 0) {
        _ensureImpactRelation(
            $chain_host_type, $chain_host_id,
            'DatabaseInstance', $chain_instance_id,
            $port_label . ' (host)'
        );
    }

} elseif (!$locked) {
    // ── Unlock: rebuild the impact name from remaining locked connections.
    //    Empty name → last port unlocked → remove relation entirely.
    //    Non-empty  → other ports still locked → update name only. ────────
    $eff_type = ($remote_itemtype && $remote_items_id > 0) ? $remote_itemtype : 'Computer';
    $new_name = _buildImpactName($computers_id, $eff_type, $remote_items_id);

    if ($new_name === '') {
        _removeImpactRelation('Computer', $computers_id, $eff_type, $remote_items_id);
        _removeImpactRelation($eff_type, $remote_items_id, 'Computer', $computers_id);
        _removeImpactRelation('Computer', $computers_id, 'DatabaseInstance', $remote_items_id);
        _removeImpactRelation('DatabaseInstance', $remote_items_id, 'Computer', $computers_id);
        // Also clean inbound instance-routed edges (local DatabaseInstance ↔ client)
        if (class_exists('PluginNetstatconnectionsResolver')) {
            $li = PluginNetstatconnectionsResolver::resolveToInstance('Computer', $computers_id, $svcPort);
            if ($li) {
                _removeImpactRelation('DatabaseInstance', (int)$li['id'], $eff_type, $remote_items_id);
                _removeImpactRelation($eff_type, $remote_items_id, 'DatabaseInstance', (int)$li['id']);
            }
        }
    } else {
        // Other ports still locked — update name on whichever direction exists
        _updateImpactName('Computer', $computers_id, $eff_type, $remote_items_id, $new_name);
        _updateImpactName($eff_type, $remote_items_id, 'Computer', $computers_id, $new_name);
        _updateImpactName('Computer', $computers_id, 'DatabaseInstance', $remote_items_id, $new_name);
        _updateImpactName('DatabaseInstance', $remote_items_id, 'Computer', $computers_id, $new_name);
    }
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

// ── Helpers ───────────────────────────────────────────────────────────

/**
 * Upsert an impact relation with an exact name (no append logic).
 * Used for the main Computer↔Remote relation where _buildImpactName
 * already computed the full accumulated label.
 */
function _setImpactRelation(string $src_type, int $src_id, string $dst_type, int $dst_id, string $name): void {
    global $DB;
    if ($src_id <= 0 || $dst_id <= 0) return;

    $where = [
        'itemtype_source'   => $src_type, 'items_id_source'   => $src_id,
        'itemtype_impacted' => $dst_type, 'items_id_impacted' => $dst_id,
    ];
    $exists = $DB->request(['FROM' => 'glpi_impactrelations', 'WHERE' => $where, 'LIMIT' => 1])->current();

    if ($exists) {
        $DB->update('glpi_impactrelations', ['name' => $name], $where);
    } else {
        $DB->insert('glpi_impactrelations', array_merge($where, ['name' => $name]));
    }
}

/**
 * Insert or UPDATE an impact relation — appends the new port label to the
 * existing name if the relation already exists (used for chain host relations).
 */
function _ensureImpactRelation(string $src_type, int $src_id, string $dst_type, int $dst_id, string $name): void {
    global $DB;
    if ($src_id <= 0 || $dst_id <= 0) return;

    $where = [
        'itemtype_source'   => $src_type, 'items_id_source'   => $src_id,
        'itemtype_impacted' => $dst_type, 'items_id_impacted' => $dst_id,
    ];

    $exists = $DB->request(['FROM' => 'glpi_impactrelations', 'WHERE' => $where, 'LIMIT' => 1])->current();

    if (!$exists) {
        $DB->insert('glpi_impactrelations', array_merge($where, ['name' => $name]));
    } else {
        // Append the new label if not already present
        $parts = array_values(array_filter(array_map('trim', explode(',', $exists['name'] ?? ''))));
        if (!in_array($name, $parts, true)) {
            $parts[] = $name;
            sort($parts);
            $DB->update('glpi_impactrelations', ['name' => implode(', ', $parts)], $where);
        }
    }
}

/**
 * Build a sorted, comma-separated list of port labels for all locked
 * connections between $computers_id and the given remote CI.
 * Used to rebuild the impact relation name after an unlock.
 */
function _buildImpactName(int $computers_id, string $remote_type, int $remote_id): string {
    global $DB;
    if ($computers_id <= 0 || $remote_id <= 0) return '';

    $rows = $DB->request([
        'SELECT' => ['service_port', 'protocol'],
        'FROM'   => 'glpi_plugin_netstatconnections_connections',
        'WHERE'  => [
            'computers_id'    => $computers_id,
            'remote_items_id' => $remote_id,
            'remote_itemtype' => $remote_type,
            'is_locked'       => 1,
        ],
    ]);
    $labels = [];
    foreach ($rows as $r) {
        $label = PluginNetstatconnectionsPort::getBadgeLabel((int)($r['service_port'] ?? 0), $r['protocol'] ?? 'TCP');
        if ($label !== '' && !in_array($label, $labels, true)) {
            $labels[] = $label;
        }
    }
    sort($labels);
    return implode(', ', $labels);
}

/**
 * Update the name of an existing impact relation (no insert).
 * Safe to call even if the relation does not exist.
 */
function _updateImpactName(string $src_type, int $src_id, string $dst_type, int $dst_id, string $name): void {
    global $DB;
    if ($src_id <= 0 || $dst_id <= 0) return;

    $DB->update('glpi_impactrelations', ['name' => $name], [
        'itemtype_source'   => $src_type, 'items_id_source'   => $src_id,
        'itemtype_impacted' => $dst_type, 'items_id_impacted' => $dst_id,
    ]);
}

function _removeImpactRelation(string $src_type, int $src_id, string $dst_type, int $dst_id): void {
    global $DB;
    if ($src_id <= 0 || $dst_id <= 0) return;

    $DB->delete('glpi_impactrelations', [
        'itemtype_source'   => $src_type, 'items_id_source'   => $src_id,
        'itemtype_impacted' => $dst_type, 'items_id_impacted' => $dst_id,
    ]);
}
