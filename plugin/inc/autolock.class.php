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
                    'remote_itemtype' => ['Computer', 'Cluster'],
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

            // Clean both directions first
            self::removeImpactRelation('Computer', $computers_id, $remote_type, $remote_id);
            self::removeImpactRelation($remote_type, $remote_id, 'Computer', $computers_id);

            if ($direction === 'impacts') {
                // Remote impacts us (if remote goes down, we break)
                $src_type = $remote_type; $src_id = $remote_id;
                $dst_type = 'Computer';   $dst_id = $computers_id;
            } else {
                // We impact them (they depend on us)
                $src_type = 'Computer';   $src_id = $computers_id;
                $dst_type = $remote_type; $dst_id = $remote_id;
            }

            self::ensureImpactItem($src_type, $src_id);
            self::ensureImpactItem($dst_type, $dst_id);
            self::ensureImpactRelation($src_type, $src_id, $dst_type, $dst_id, $label);
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

    private static function ensureImpactRelation(string $src_type, int $src_id, string $dst_type, int $dst_id, string $name): void {
        global $DB;
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
