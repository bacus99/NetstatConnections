<?php
/**
 * bulk_lock.php — Bulk lock / unlock all inbound connections on a given local port.
 *
 * v1.4 — Pillar 4: Server-side bulk lock
 *
 * GET parameters:
 *   computers_id  int   ID of the server (local computer)
 *   local_port    int   Service port being served (e.g. 1433 for MSSQL)
 *   protocol      str   TCP | UDP   (default TCP)
 *   locked        int   1 = lock all,  0 = unlock all
 *
 * Behaviour:
 *   Lock:   Sets is_locked=1 + creates an impact relation for each unique
 *           remote CI that connects inbound to this port.
 *           Direction is always "depends" (remote client depends on local server).
 *   Unlock: Sets is_locked=0.  Impact relation is removed only when NO other
 *           locked port to that same remote CI remains.
 *
 * Returns JSON: { success, locked, count_processed, count_skipped, port }
 */

require_once __DIR__ . '/../inc/_bootstrap.php';

header('Content-Type: application/json');

Session::checkLoginUser();

global $DB;

$computers_id = (int)($_GET['computers_id'] ?? 0);
$local_port   = (int)($_GET['local_port']   ?? 0);
$locked       = (int)($_GET['locked']       ?? 1);
$protocol     = strtoupper(trim($_GET['protocol'] ?? 'TCP'));

if ($computers_id <= 0 || $local_port <= 0) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$port_label = PluginNetstatconnectionsPort::getBadgeLabel($local_port, $protocol);

// ── Fetch all active inbound rows for this port ───────────────────────
$all_rows = $DB->request([
    'FROM'  => 'glpi_plugin_netstatconnections_connections',
    'WHERE' => [
        'computers_id'      => $computers_id,
        'local_port'        => $local_port,
        'conn_direction'    => 'inbound',
        'connection_status' => 'active',
    ],
]);

// ── Deduplicate by remote_addr — one impact relation per remote CI ─────
$by_remote = [];
foreach ($all_rows as $row) {
    $ra = $row['remote_addr'];
    if (!isset($by_remote[$ra])) {
        $by_remote[$ra] = $row;          // keep first row as representative
    } elseif ((int)$row['remote_items_id'] > 0 && (int)$by_remote[$ra]['remote_items_id'] === 0) {
        $by_remote[$ra] = $row;          // prefer a row that already has a CI resolved
    }
}

if (empty($by_remote)) {
    echo json_encode(['success' => true, 'locked' => $locked, 'count_processed' => 0,
                      'count_skipped' => 0, 'port' => $local_port]);
    exit;
}

$count_processed = 0;
$count_skipped   = 0;

// ── Inbound: remote clients consume OUR service → they depend on us, so
// this computer IMPACTS them (canonical: 'impacts' = Computer → remote).
// Impact relation: Computer/$computers_id → remote_itemtype/remote_id
$direction = 'impacts';

// DB-port inbound: the database lives HERE — route the LOCAL endpoint through
// the local DatabaseInstance so client edges attach to the instance, never the
// bare host. Resolved once (same local port for every row in this bulk op).
$local_edge_type = 'Computer';
$local_edge_id   = $computers_id;
$chain_needed    = false;
try {
    $pd = $DB->request([
        'SELECT' => ['is_database_port'],
        'FROM'   => 'glpi_plugin_netstatconnections_ports',
        'WHERE'  => ['port_number' => $local_port, 'protocol' => $protocol],
        'LIMIT'  => 1,
    ])->current();
    if ((int)($pd['is_database_port'] ?? 0) === 1 && class_exists('PluginNetstatconnectionsResolver')) {
        $li = PluginNetstatconnectionsResolver::resolveToInstance('Computer', $computers_id, $local_port);
        if ($li) {
            $local_edge_type = 'DatabaseInstance';
            $local_edge_id   = (int)$li['id'];
            $chain_needed    = true;
        }
    }
} catch (\Throwable $e) {}

foreach ($by_remote as $remote_addr => $conn) {

    // ── Per-port WHERE for UPDATE ─────────────────────────────────────
    $where_port = [
        'computers_id'   => $computers_id,
        'remote_addr'    => $remote_addr,
        'local_port'     => $local_port,
    ];

    if ($locked) {
        // ── Lock ──────────────────────────────────────────────────────
        $DB->update('glpi_plugin_netstatconnections_connections', [
            'is_locked'        => 1,
            'impact_direction' => $direction,
            'service_port'     => $local_port,
        ], $where_port);

        // Resolve remote CI
        $remote_items_id = (int)($conn['remote_items_id'] ?? 0);
        $remote_itemtype = $conn['remote_itemtype'] ?? '';

        if ($remote_items_id === 0 || empty($remote_itemtype)) {
            // Try any sibling row for this IP that already has a resolution
            $sibling = $DB->request([
                'SELECT' => ['remote_items_id', 'remote_itemtype'],
                'FROM'   => 'glpi_plugin_netstatconnections_connections',
                'WHERE'  => [
                    'computers_id'    => $computers_id,
                    'remote_addr'     => $remote_addr,
                    ['NOT' => ['remote_items_id' => null]],
                    ['NOT' => ['remote_items_id' => 0]],
                ],
                'LIMIT'  => 1,
            ])->current();

            if ($sibling) {
                $remote_items_id = (int)$sibling['remote_items_id'];
                $remote_itemtype = $sibling['remote_itemtype'];
            } else {
                $resolved = PluginNetstatconnectionsResolver::resolveIP($remote_addr);
                if (!empty($resolved['remote_items_id'])) {
                    $remote_items_id = (int)$resolved['remote_items_id'];
                    $remote_itemtype = $resolved['remote_itemtype'];
                    // Persist resolution for all rows with this IP
                    try {
                        $DB->update('glpi_plugin_netstatconnections_connections', [
                            'remote_items_id' => $remote_items_id,
                            'remote_itemtype' => $remote_itemtype,
                            'remote_scope'    => $resolved['remote_scope'],
                            'resolved_via'    => 'lock',
                            'resolved_at'     => new \Glpi\DBAL\QueryExpression('NOW()'),
                        ], ['computers_id' => $computers_id, 'remote_addr' => $remote_addr]);
                    } catch (\Throwable $e) {}
                }
            }
        }

        if ($remote_items_id > 0 && $remote_items_id !== $computers_id && !empty($remote_itemtype)) {
            // Inbound: this server's service impacts its clients
            // (canonical 'impacts': src=local service endpoint, dst=remote client).
            // Rebuild full name from ALL locked ports to this remote CI
            $accumulated_name = _bulkBuildImpactName($computers_id, $remote_itemtype, $remote_items_id);
            if ($accumulated_name === '') $accumulated_name = $port_label;
            // When routed via the local instance, drop legacy direct host↔client edges
            if ($local_edge_type === 'DatabaseInstance') {
                _bulkRemoveImpactRelation('Computer', $computers_id, $remote_itemtype, $remote_items_id);
                _bulkRemoveImpactRelation($remote_itemtype, $remote_items_id, 'Computer', $computers_id);
            }
            // Remove wrong direction only (remote→local would be "depends")
            _bulkRemoveImpactRelation($remote_itemtype, $remote_items_id, $local_edge_type, $local_edge_id);
            _bulkSetImpactRelation($local_edge_type, $local_edge_id, $remote_itemtype, $remote_items_id, $accumulated_name);
            $count_processed++;
        } else {
            $count_skipped++;
        }

    } else {
        // ── Unlock ────────────────────────────────────────────────────
        $DB->update('glpi_plugin_netstatconnections_connections', [
            'is_locked' => 0,
        ], $where_port);

        $remote_items_id = (int)($conn['remote_items_id'] ?? 0);
        $remote_itemtype = $conn['remote_itemtype'] ?? '';
        $eff_type = ($remote_itemtype && $remote_items_id > 0) ? $remote_itemtype : 'Computer';

        // Rebuild the name from remaining locked connections
        $new_name = _bulkBuildImpactName($computers_id, $eff_type, $remote_items_id);

        if ($new_name === '') {
            _bulkRemoveImpactRelation($eff_type, $remote_items_id, 'Computer', $computers_id);
            _bulkRemoveImpactRelation('Computer', $computers_id, $eff_type, $remote_items_id);
            // Also clean instance-routed edges (local DatabaseInstance ↔ client)
            if ($local_edge_type === 'DatabaseInstance') {
                _bulkRemoveImpactRelation($local_edge_type, $local_edge_id, $eff_type, $remote_items_id);
                _bulkRemoveImpactRelation($eff_type, $remote_items_id, $local_edge_type, $local_edge_id);
            }
        } else {
            _bulkUpdateImpactName($eff_type, $remote_items_id, 'Computer', $computers_id, $new_name);
            _bulkUpdateImpactName('Computer', $computers_id, $eff_type, $remote_items_id, $new_name);
            if ($local_edge_type === 'DatabaseInstance') {
                _bulkUpdateImpactName($local_edge_type, $local_edge_id, $eff_type, $remote_items_id, $new_name);
            }
        }
        $count_processed++;
    }
}

// Host chain (once per bulk op): the host computer carries the instance —
// Computer → impacts → DatabaseInstance, labelled "(host)".
if ($locked && $chain_needed && $count_processed > 0) {
    _bulkSetImpactRelation('Computer', $computers_id, 'DatabaseInstance', $local_edge_id, $port_label . ' (host)');
}

echo json_encode([
    'success'         => true,
    'locked'          => $locked,
    'count_processed' => $count_processed,
    'count_skipped'   => $count_skipped,
    'port'            => $local_port,
]);

// ── Helpers ───────────────────────────────────────────────────────────

/** A CI must actually load, or GLPI's Impact tab fatals on the relation. */
function _bulkItemExists(string $type, int $id): bool {
    static $cache = [];
    if ($id <= 0 || $type === '' || !class_exists($type)) return false;
    $k = $type . ':' . $id;
    if (array_key_exists($k, $cache)) return $cache[$k];
    $o = new $type();
    return $cache[$k] = (bool)$o->getFromDB($id);
}

/** Upsert an impact relation with an exact pre-computed name (no append). */
function _bulkSetImpactRelation(string $src_type, int $src_id, string $dst_type, int $dst_id, string $name): void {
    global $DB;
    if (!_bulkItemExists($src_type, $src_id) || !_bulkItemExists($dst_type, $dst_id)) return;

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

function _bulkEnsureImpactRelation(string $src_type, int $src_id, string $dst_type, int $dst_id, string $name): void {
    global $DB;
    if (!_bulkItemExists($src_type, $src_id) || !_bulkItemExists($dst_type, $dst_id)) return;

    $where = [
        'itemtype_source'   => $src_type, 'items_id_source'   => $src_id,
        'itemtype_impacted' => $dst_type, 'items_id_impacted' => $dst_id,
    ];
    $exists = $DB->request(['FROM' => 'glpi_impactrelations', 'WHERE' => $where, 'LIMIT' => 1])->current();

    if (!$exists) {
        $DB->insert('glpi_impactrelations', array_merge($where, ['name' => $name]));
    } else {
        // Append port label if not already present
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
 */
function _bulkBuildImpactName(int $computers_id, string $remote_type, int $remote_id): string {
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

/** Update the name of an existing impact relation (no insert). */
function _bulkUpdateImpactName(string $src_type, int $src_id, string $dst_type, int $dst_id, string $name): void {
    global $DB;
    if ($src_id <= 0 || $dst_id <= 0) return;
    $DB->update('glpi_impactrelations', ['name' => $name], [
        'itemtype_source'   => $src_type, 'items_id_source'   => $src_id,
        'itemtype_impacted' => $dst_type, 'items_id_impacted' => $dst_id,
    ]);
}

function _bulkRemoveImpactRelation(string $src_type, int $src_id, string $dst_type, int $dst_id): void {
    global $DB;
    if ($src_id <= 0 || $dst_id <= 0) return;

    $DB->delete('glpi_impactrelations', [
        'itemtype_source'   => $src_type, 'items_id_source'   => $src_id,
        'itemtype_impacted' => $dst_type, 'items_id_impacted' => $dst_id,
    ]);
}
