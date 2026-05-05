<?php
/**
 * PluginNetstatconnectionsAutolock
 *
 * v1.2.0 — Proper inbound detection:
 *   - Outbound: remote_port matches a policy → direction from policy
 *   - Inbound:  local_port matches a policy AND conn_direction='inbound' → direction = 'depends'
 *   - Inline resolver if remote_scope is empty
 *   - Creates impact relations on lock
 */
class PluginNetstatconnectionsAutolock {

    private static ?array $policy_cache = null;

    // ── Public API ───────────────────────────────────────────────────

    /**
     * Run auto-lock for a single computer after inventory.
     */
    public static function processForComputer(int $computers_id): int {
        $policies = self::getPolicies();
        if (empty($policies)) return 0;

        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_netstatconnections_connections',
            'WHERE' => [
                'computers_id'      => $computers_id,
                'is_locked'         => 0,
                'connection_status' => 'active',
                ['NOT' => ['remote_addr' => '']],
            ],
        ]);

        $locked = 0;
        foreach ($iter as $row) {
            $result = self::matchPolicy($row, $policies);
            if (!$result) continue;
            $locked += self::applyPolicy($row, $result['policy'], $computers_id, $result['is_inbound']);
        }

        return $locked;
    }

    /**
     * Cron sweep — process all unlocked active rows across all computers.
     */
    public static function cronSweep(): int {
        $policies = self::getPolicies();
        if (empty($policies)) return 0;

        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_netstatconnections_connections',
            'WHERE' => [
                'is_locked'         => 0,
                'connection_status' => 'active',
                ['NOT' => ['remote_addr' => '']],
            ],
            'LIMIT' => 2000,
        ]);

        $locked = 0;
        foreach ($iter as $row) {
            $result = self::matchPolicy($row, $policies);
            if (!$result) continue;
            $locked += self::applyPolicy($row, $result['policy'], (int)$row['computers_id'], $result['is_inbound']);
        }

        return $locked;
    }

    // ── Policy matching ──────────────────────────────────────────────

    /**
     * Match a connection row against port policies.
     * Returns ['policy' => ..., 'is_inbound' => bool] or null.
     */
    private static function matchPolicy(array $row, array $policies): ?array {
        $rport = (int)($row['remote_port'] ?? 0);
        $lport = (int)($row['local_port'] ?? 0);
        $proto = strtoupper($row['protocol'] ?? 'TCP');
        $conn_dir = $row['conn_direction'] ?? '';

        // Strategy 1: remote_port matches a policy → outbound
        $key_remote = $rport . '_' . $proto;
        if (isset($policies[$key_remote])) {
            return ['policy' => $policies[$key_remote], 'is_inbound' => false];
        }

        // Strategy 2: local_port matches a policy → inbound
        // Criteria: conn_direction is 'inbound' (set by collector based on LISTEN detection)
        // OR remote_port is ephemeral (>= 1024) and local_port is a known service
        $key_local = $lport . '_' . $proto;
        if (isset($policies[$key_local])) {
            if ($conn_dir === 'inbound' || ($rport >= 1024 && $lport < 1024) || $rport >= 49152) {
                return ['policy' => $policies[$key_local], 'is_inbound' => true];
            }
        }

        return null;
    }

    // ── Core logic ───────────────────────────────────────────────────

    private static function applyPolicy(array $row, array $policy, int $computers_id, bool $is_inbound): int {
        global $DB;

        // Inbound → they depend on us (if our service goes down, they break)
        // Outbound → we depend on them (use policy direction, default 'impacts')
        $direction = $is_inbound
            ? 'depends'
            : ($policy['auto_direction'] ?? 'impacts');

        $conn_direction = $is_inbound ? 'inbound' : 'outbound';
        $svcport = $is_inbound ? (int)($row['local_port'] ?? 0) : (int)($row['remote_port'] ?? 0);

        // ── Resolve remote CI if not already done ────────────────────
        $remote_id   = (int)($row['remote_items_id'] ?? 0);
        $remote_type = $row['remote_itemtype'] ?? '';

        if ($remote_id === 0 || empty($remote_type)) {
            // Try inline resolve
            if (class_exists('PluginNetstatconnectionsResolver')) {
                $resolved = PluginNetstatconnectionsResolver::resolveIP($row['remote_addr'] ?? '');
                $remote_id   = (int)($resolved['remote_items_id'] ?? 0);
                $remote_type = $resolved['remote_itemtype'] ?? '';

                if ($remote_id > 0) {
                    $DB->update('glpi_plugin_netstatconnections_connections', [
                        'remote_items_id' => $remote_id,
                        'remote_itemtype' => $remote_type,
                        'remote_scope'    => $resolved['remote_scope'] ?? 'internal',
                        'resolved_via'    => $resolved['resolved_via'] ?? 'autolock',
                        'resolved_at'     => date('Y-m-d H:i:s'),
                    ], ['id' => (int)$row['id']]);
                }
            }

            // Still unresolved? Try sibling match
            if ($remote_id === 0) {
                $sibling_where = [
                    'computers_id'    => $computers_id,
                    'remote_addr'     => $row['remote_addr'],
                    'protocol'        => $row['protocol'],
                    'remote_itemtype' => ['Computer', 'Cluster', 'DatabaseInstance'],
                    ['NOT' => ['remote_items_id' => null]],
                    ['NOT' => ['remote_items_id' => 0]],
                ];
                // Group by service port
                if ($is_inbound) {
                    $sibling_where['local_port'] = (int)$row['local_port'];
                } else {
                    $sibling_where['remote_port'] = (int)$row['remote_port'];
                }

                $sibling = $DB->request([
                    'SELECT' => ['remote_items_id', 'remote_itemtype'],
                    'FROM'   => 'glpi_plugin_netstatconnections_connections',
                    'WHERE'  => $sibling_where,
                    'LIMIT'  => 1,
                ])->current();

                if ($sibling) {
                    $remote_id   = (int)$sibling['remote_items_id'];
                    $remote_type = $sibling['remote_itemtype'];
                    $DB->update('glpi_plugin_netstatconnections_connections', [
                        'remote_items_id' => $remote_id,
                        'remote_itemtype' => $remote_type,
                        'remote_scope'    => 'internal',
                        'resolved_via'    => 'sibling',
                        'resolved_at'     => date('Y-m-d H:i:s'),
                    ], ['id' => (int)$row['id']]);
                }
            }
        }

        // ── Lock the row ─────────────────────────────────────────────
        $update = [
            'is_locked'        => 1,
            'impact_direction' => $direction,
            'conn_direction'   => $conn_direction,
            'service_port'     => $svcport,
        ];

        // Lock by IP — all rows with same remote_addr for this computer
        $lock_where = [
            'computers_id' => $computers_id,
            'remote_addr'  => $row['remote_addr'],
            'is_locked'    => 0,
        ];
        $DB->update('glpi_plugin_netstatconnections_connections', $update, $lock_where);

        // ── Impact relation ──────────────────────────────────────────
        if ($remote_id > 0 && !($remote_type === 'Computer' && $remote_id === $computers_id)) {
            $proto = $row['protocol'] ?? 'TCP';
            $label = self::getPortLabel($svcport, $proto);

            // ── Pillar 2: DatabaseInstance resolution ────────────────
            // If this is a database port, try to resolve to a DatabaseInstance
            $is_db_port = (int)($policy['is_database_port'] ?? 0);
            $impact_target_type = $remote_type;
            $impact_target_id   = $remote_id;
            $chain_host_type    = '';
            $chain_host_id      = 0;

            if ($is_db_port && class_exists('PluginNetstatconnectionsResolver')) {
                $instance = PluginNetstatconnectionsResolver::resolveToInstance(
                    $remote_type, $remote_id, $svcport
                );
                if ($instance) {
                    // Target the DatabaseInstance instead of the Computer/Cluster
                    $impact_target_type = 'DatabaseInstance';
                    $impact_target_id   = (int)$instance['id'];

                    // Pillar 3: chain DatabaseInstance → host (Computer or Cluster)
                    $chain = PluginNetstatconnectionsResolver::resolveInstanceChain((int)$instance['id']);
                    if (!empty($chain) && $chain['host_id'] > 0 && !empty($chain['host_type'])) {
                        $chain_host_type = $chain['host_type'];
                        $chain_host_id   = $chain['host_id'];
                    }

                    // Update the connection row with the resolved instance
                    try {
                        $DB->update('glpi_plugin_netstatconnections_connections', [
                            'remote_items_id' => $impact_target_id,
                            'remote_itemtype' => $impact_target_type,
                            'resolved_via'    => 'db_instance',
                        ], ['id' => (int)$row['id']]);
                    } catch (\Throwable $e) {
                        $DB->update('glpi_plugin_netstatconnections_connections', [
                            'remote_items_id' => $impact_target_id,
                            'remote_itemtype' => $impact_target_type,
                        ], ['id' => (int)$row['id']]);
                    }
                }
            }

            if ($direction === 'impacts') {
                // Remote impacts us (if remote goes down, we break)
                $src_type = $impact_target_type; $src_id = $impact_target_id;
                $dst_type = 'Computer';          $dst_id = $computers_id;
            } else {
                // We impact them (they depend on us)
                $src_type = 'Computer';          $src_id = $computers_id;
                $dst_type = $impact_target_type; $dst_id = $impact_target_id;
            }

            // Build accumulated name from ALL currently-locked connections to this CI.
            // Only remove the wrong-direction relation — never wipe the forward one,
            // as it would erase previously accumulated port labels.
            $accumulated_label = self::buildImpactName($computers_id, $impact_target_type, $impact_target_id);
            if ($accumulated_label === '') $accumulated_label = $label;

            self::removeImpactRelation($dst_type, $dst_id, $src_type, $src_id);   // wrong direction only

            self::ensureImpactItem($src_type, $src_id);
            self::ensureImpactItem($dst_type, $dst_id);
            self::setImpactRelation($src_type, $src_id, $dst_type, $dst_id, $accumulated_label);

            // ── Pillar 3: Host → DatabaseInstance (host failure takes down the instance)
            if ($chain_host_id > 0 && !empty($chain_host_type)) {
                self::ensureImpactItem($chain_host_type, $chain_host_id);
                self::ensureImpactRelation(
                    $chain_host_type, $chain_host_id,
                    'DatabaseInstance', $impact_target_id,
                    $label . ' (host)'
                );
            }
        }

        return 1;
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private static function getPortLabel(int $port, string $proto): string {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['name'],
            'FROM'   => 'glpi_plugin_netstatconnections_ports',
            'WHERE'  => [
                'port_number' => $port,
                'protocol'    => strtoupper($proto),
            ],
            'LIMIT' => 1,
        ])->current();
        return $row ? $row['name'] : strtoupper($proto) . ' ' . $port;
    }

    private static function ensureImpactItem(string $type, int $id): void {
        global $DB;
        $exists = $DB->request([
            'FROM'  => 'glpi_impactitems',
            'WHERE' => ['itemtype' => $type, 'items_id' => $id],
            'LIMIT' => 1,
        ])->current();
        if (!$exists) {
            $DB->insert('glpi_impactitems', [
                'itemtype' => $type,
                'items_id' => $id,
            ]);
        }
    }

    /**
     * Build a sorted, comma-separated list of port labels for all locked
     * connections from $computers_id to the given remote CI.
     * Called before creating/updating an impact relation so the name always
     * reflects the full set of locked ports, not just the most recent one.
     */
    private static function buildImpactName(int $computers_id, string $remote_type, int $remote_id): string {
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
            $label = self::getPortLabel((int)($r['service_port'] ?? 0), $r['protocol'] ?? 'TCP');
            if ($label !== '' && !in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }
        sort($labels);
        return implode(', ', $labels);
    }

    /** Upsert an impact relation with an exact pre-computed name (no append). */
    private static function setImpactRelation(string $src_type, int $src_id, string $dst_type, int $dst_id, string $name): void {
        global $DB;
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

    private static function ensureImpactRelation(string $src_type, int $src_id, string $dst_type, int $dst_id, string $name): void {
        global $DB;
        $where = [
            'itemtype_source'   => $src_type, 'items_id_source'   => $src_id,
            'itemtype_impacted' => $dst_type, 'items_id_impacted' => $dst_id,
        ];
        $exists = $DB->request(['FROM' => 'glpi_impactrelations', 'WHERE' => $where, 'LIMIT' => 1])->current();
        if (!$exists) {
            $DB->insert('glpi_impactrelations', array_merge($where, ['name' => $name]));
        } else {
            // Append the new port label if not already present in the name
            $parts = array_values(array_filter(array_map('trim', explode(',', $exists['name'] ?? ''))));
            if (!in_array($name, $parts, true)) {
                $parts[] = $name;
                sort($parts);
                $DB->update('glpi_impactrelations', ['name' => implode(', ', $parts)], $where);
            }
        }
    }

    private static function removeImpactRelation(string $src_type, int $src_id, string $dst_type, int $dst_id): void {
        global $DB;
        $DB->delete('glpi_impactrelations', [
            'itemtype_source'   => $src_type, 'items_id_source'   => $src_id,
            'itemtype_impacted' => $dst_type, 'items_id_impacted' => $dst_id,
        ]);
    }

    /**
     * Load all port policies with auto_lock = 1, keyed by "port_protocol".
     */
    private static function getPolicies(): array {
        if (self::$policy_cache !== null) return self::$policy_cache;

        global $DB;
        self::$policy_cache = [];

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_netstatconnections_ports',
            'WHERE' => ['auto_lock' => 1],
        ]);

        foreach ($iter as $row) {
            $key = (int)$row['port_number'] . '_' . strtoupper($row['protocol']);
            self::$policy_cache[$key] = $row;
        }

        return self::$policy_cache;
    }
}
