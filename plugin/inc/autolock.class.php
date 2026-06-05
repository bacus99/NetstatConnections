<?php
/**
 * PluginNetstatconnectionsAutolock
 *
 * v1.3.0 — Cluster-aware impact routing:
 *   - Outbound: remote_port matches a policy → direction from policy
 *   - Inbound:  local_port matches a policy AND conn_direction='inbound' → direction = 'depends'
 *   - Inline resolver if remote_scope is empty
 *   - Creates impact relations on lock
 *   - When DBI is on a Cluster (AlwaysOn / FCI), the Source ↔ DBI direct edge
 *     is replaced by Source ↔ Cluster + Cluster → DBI (host), giving a clean
 *     linear path through the cluster listener.
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
        global $DB;

        $locked   = 0;
        $policies = self::getPolicies();

        if (!empty($policies)) {
            $iter = $DB->request([
                'FROM'  => 'glpi_plugin_netstatconnections_connections',
                'WHERE' => [
                    'is_locked'         => 0,
                    'connection_status' => 'active',
                    ['NOT' => ['remote_addr' => '']],
                ],
                'LIMIT' => 2000,
            ]);

            foreach ($iter as $row) {
                $result = self::matchPolicy($row, $policies);
                if (!$result) continue;
                $locked += self::applyPolicy($row, $result['policy'], (int)$row['computers_id'], $result['is_inbound']);
            }
        }

        // ── Self-heal pass (v2.8.2): re-run relation building on LOCKED rows
        // of DATABASE ports. Locked rows are never reprocessed by the main
        // sweep, so rows locked before instance routing / the 2.8.1 semantics
        // fix keep stale direct Computer↔Computer relations forever. Re-running
        // applyPolicy on them is idempotent and:
        //   - upgrades outbound DB-port targets to the DatabaseInstance,
        //   - routes inbound DB-port locals through the local instance,
        //   - rebuilds arrows under the corrected direction semantics,
        //   - removes the legacy direct host↔client edges.
        // Uses ALL database ports (not just auto_lock=1) so manually-locked
        // rows heal too.
        $db_policies = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_netstatconnections_ports',
            'WHERE' => ['is_database_port' => 1],
        ]) as $p) {
            $db_policies[(int)$p['port_number'] . '_' . strtoupper($p['protocol'])] = $p;
        }

        if (!empty($db_policies)) {
            $iter = $DB->request([
                'FROM'  => 'glpi_plugin_netstatconnections_connections',
                'WHERE' => [
                    'is_locked'         => 1,
                    'connection_status' => 'active',
                    ['NOT' => ['remote_addr' => '']],
                ],
                'LIMIT' => 1000,
            ]);
            foreach ($iter as $row) {
                $result = self::matchPolicy($row, $db_policies);
                if (!$result) continue;
                self::applyPolicy($row, $result['policy'], (int)$row['computers_id'], $result['is_inbound']);
            }
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

        // Default assignment — the SERVICE impacts its CONSUMERS:
        //   Outbound (we consume a remote service, e.g. SCOM → SQL) → 'depends'
        //     (we depend on the destination; if it dies, we break),
        //     overridable per-port via auto_direction.
        //   Inbound (remote clients consume OUR service) → 'impacts'
        //     (they depend on us; if we die, they break).
        // A direction already stored on the row (manual toggle / enricher) is
        // STICKY — the hourly self-heal pass must not revert admin choices.
        $direction = ($row['impact_direction'] ?? '') ?: ($is_inbound
            ? 'impacts'
            : ($policy['auto_direction'] ?? 'depends'));

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

            // ── Pillar 2: DatabaseInstance resolution (direction-aware) ──
            // The DATABASE side of a DB-port connection is:
            //   outbound → the REMOTE host (we consume a remote DB)
            //   inbound  → the LOCAL computer (remote clients consume OUR DB)
            // Route the database-side endpoint through its DatabaseInstance so
            // 1433-style edges attach to the instance, never the bare host.
            $is_db_port = (int)($policy['is_database_port'] ?? 0);
            $impact_target_type = $remote_type;
            $impact_target_id   = $remote_id;
            $local_edge_type    = 'Computer';
            $local_edge_id      = $computers_id;
            $chain_host_type    = '';
            $chain_host_id      = 0;
            $chain_instance_id  = 0;

            if ($is_db_port && !$is_inbound && $remote_type === 'DatabaseInstance'
                && class_exists('PluginNetstatconnectionsResolver')) {
                // Remote is ALREADY an instance (row locked/healed earlier).
                // Recover its host chain so cluster routing (Pillar 3.5) and the
                // host edge are preserved when this row is reprocessed by the
                // self-heal pass — without this, re-running would rebuild a
                // direct Source↔DBI edge and undo the AlwaysOn routing.
                $chain_instance_id = $remote_id;
                $chain = PluginNetstatconnectionsResolver::resolveInstanceChain($remote_id);
                if (!empty($chain) && $chain['host_id'] > 0 && !empty($chain['host_type'])) {
                    $chain_host_type = $chain['host_type'];
                    $chain_host_id   = $chain['host_id'];
                }
            } elseif ($is_db_port && !$is_inbound && class_exists('PluginNetstatconnectionsResolver')) {
                // Outbound: upgrade the REMOTE target to its DatabaseInstance
                $instance = PluginNetstatconnectionsResolver::resolveToInstance(
                    $remote_type, $remote_id, $svcport
                );
                if ($instance) {
                    $impact_target_type = 'DatabaseInstance';
                    $impact_target_id   = (int)$instance['id'];
                    $chain_instance_id  = $impact_target_id;

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
            } elseif ($is_db_port && $is_inbound && class_exists('PluginNetstatconnectionsResolver')) {
                // Inbound: the database lives HERE — substitute the LOCAL endpoint
                // with the local DatabaseInstance (the remote is just a client; it
                // has no instance). Host chain: this Computer → the instance.
                $local_inst = PluginNetstatconnectionsResolver::resolveToInstance(
                    'Computer', $computers_id, $svcport
                );
                if ($local_inst) {
                    $local_edge_type   = 'DatabaseInstance';
                    $local_edge_id     = (int)$local_inst['id'];
                    $chain_instance_id = $local_edge_id;
                    $chain_host_type   = 'Computer';
                    $chain_host_id     = $computers_id;
                }
            }

            // ── Pillar 3.5: Cluster routing ───────────────────────────────
            // When the DBI lives on a Cluster (AlwaysOn / FCI), the client
            // doesn't connect to the DBI directly — it connects to the
            // cluster's listener. The impact path is therefore:
            //
            //     Source -(MSSQL 1433)-> Cluster -(host)-> DBI
            //
            // not a confusing direct Source -> DBI edge that bypasses the
            // Cluster. We swap the client-facing target to the Cluster and
            // keep Pillar 3's Cluster -> DBI host edge below.
            $is_clustered_db = ($impact_target_type === 'DatabaseInstance'
                && $chain_host_type === 'Cluster'
                && $chain_host_id > 0);

            if ($is_clustered_db) {
                $client_edge_type = 'Cluster';
                $client_edge_id   = $chain_host_id;
            } else {
                $client_edge_type = $impact_target_type;
                $client_edge_id   = $impact_target_id;
            }

            // Canonical direction semantics (MUST match lock.php + graph.php):
            //   'impacts' → this computer IMPACTS the remote  (relation Computer → remote)
            //   'depends' → the remote impacts this computer  (relation remote → Computer)
            // i.e. the impact arrow points from the SERVICE to its CONSUMER:
            // SCORMON01 ▶ SQL01 (outbound, depends) ⇒ SQL01 → impacts → SCORMON01.
            // (Pre-2.8.1 autolock had the mapping inverted relative to manual
            // locks, producing opposite arrows depending on how a row got locked.)
            if ($direction === 'impacts') {
                $src_type = $local_edge_type;  $src_id = $local_edge_id;
                $dst_type = $client_edge_type; $dst_id = $client_edge_id;
            } else {
                $src_type = $client_edge_type; $src_id = $client_edge_id;
                $dst_type = $local_edge_type;  $dst_id = $local_edge_id;
            }

            // Build accumulated name from ALL currently-locked connections to this CI.
            // For clustered DBs, aggregate labels across every DBI hosted on the cluster
            // so the single Source<->Cluster edge shows every service the cluster offers
            // to this computer (e.g. "MSSQL 1433, AlwaysOn 5022").
            if ($is_clustered_db) {
                $accumulated_label = self::buildClusterImpactName($computers_id, $client_edge_id);
            } else {
                $accumulated_label = self::buildImpactName($computers_id, $impact_target_type, $impact_target_id);
            }
            if ($accumulated_label === '') $accumulated_label = $label;

            self::removeImpactRelation($dst_type, $dst_id, $src_type, $src_id);   // wrong direction only

            // For clustered DBs, also clean up the legacy direct DBI<->Source edge
            // created by previous versions of this plugin so the graph collapses
            // to the single Source -> Cluster -> DBI path going forward.
            if ($is_clustered_db) {
                self::removeImpactRelation('DatabaseInstance', $impact_target_id, 'Computer', $computers_id);
                self::removeImpactRelation('Computer', $computers_id, 'DatabaseInstance', $impact_target_id);
            }

            // When the LOCAL endpoint routed through its DatabaseInstance
            // (inbound DB port), clean up the legacy direct host↔client edges
            // so the path collapses to Client ← Instance ← Host.
            if ($local_edge_type === 'DatabaseInstance') {
                self::removeImpactRelation('Computer', $computers_id, $client_edge_type, $client_edge_id);
                self::removeImpactRelation($client_edge_type, $client_edge_id, 'Computer', $computers_id);
            }

            self::ensureImpactItem($src_type, $src_id);
            self::ensureImpactItem($dst_type, $dst_id);
            self::setImpactRelation($src_type, $src_id, $dst_type, $dst_id, $accumulated_label);

            // ── Pillar 3: Host → DatabaseInstance (host failure takes down the instance)
            if ($chain_host_id > 0 && !empty($chain_host_type) && $chain_instance_id > 0) {
                self::ensureImpactItem($chain_host_type, $chain_host_id);
                self::ensureImpactRelation(
                    $chain_host_type, $chain_host_id,
                    'DatabaseInstance', $chain_instance_id,
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

    /**
     * Does the CI actually exist (and load) in GLPI? Guards every impact write
     * so we never reference a deleted/missing item — GLPI's native Impact tab
     * fatals on such rows (ImpactItem::findForItem reads $item->fields['id'] on
     * an unloaded object → NULL insert). Cached per request.
     */
    private static function itemExists(string $type, int $id): bool {
        static $cache = [];
        if ($id <= 0 || $type === '' || !class_exists($type)) return false;
        $k = $type . ':' . $id;
        if (array_key_exists($k, $cache)) return $cache[$k];
        $obj = new $type();
        return $cache[$k] = (bool)$obj->getFromDB($id);
    }

    private static function ensureImpactItem(string $type, int $id): void {
        global $DB;
        if (!self::itemExists($type, $id)) return;   // never create Computer/0 or dangling
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

    /**
     * Aggregate port labels across every locked connection from $computers_id
     * to ANY DatabaseInstance hosted on the given Cluster. Used to label the
     * single Source ↔ Cluster edge in the AlwaysOn / FCI routing case.
     *
     * Returns e.g. "AlwaysOn 5022, MSSQL 1433" for a node that connects to a
     * clustered SQL server's primary + AG endpoints.
     */
    private static function buildClusterImpactName(int $computers_id, int $cluster_id): string {
        global $DB;
        if ($computers_id <= 0 || $cluster_id <= 0) return '';
        if (!$DB->tableExists('glpi_databaseinstances')) return '';

        $sql = "SELECT DISTINCT c.`service_port`, c.`protocol`
                FROM `glpi_plugin_netstatconnections_connections` AS c
                INNER JOIN `glpi_databaseinstances` AS d
                    ON c.`remote_items_id` = d.`id`
                WHERE c.`computers_id`    = " . (int)$computers_id . "
                  AND c.`is_locked`       = 1
                  AND c.`remote_itemtype` = 'DatabaseInstance'
                  AND d.`itemtype`        = 'Cluster'
                  AND d.`items_id`        = " . (int)$cluster_id . "
                  AND d.`is_deleted`      = 0";

        $result = $DB->doQuery($sql);
        $labels = [];
        while ($row = $DB->fetchAssoc($result)) {
            $label = self::getPortLabel((int)($row['service_port'] ?? 0), $row['protocol'] ?? 'TCP');
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
        if (!self::itemExists($src_type, $src_id) || !self::itemExists($dst_type, $dst_id)) return;
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
        if (!self::itemExists($src_type, $src_id) || !self::itemExists($dst_type, $dst_id)) return;
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
