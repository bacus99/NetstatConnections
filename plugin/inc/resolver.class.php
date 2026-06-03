<?php
/**
 * PluginNetstatconnectionsResolver
 * Resolves remote IP addresses to GLPI Computer / Cluster CIs.
 *
 * v1.1.2 — resolved_via uses 'unresolved' (not 'none') to match ENUM.
 */
class PluginNetstatconnectionsResolver {

    /**
     * Resolve remote IPs to GLPI CIs — cron entry point (Pillar 7).
     *
     * Two passes per run, each deduplicated by remote_addr:
     *
     *   Pass 1 — Never attempted: rows where remote_scope is null/empty.
     *             Covers brand-new connections that arrived since the last run.
     *
     *   Pass 2 — Previously unresolved: rows stamped resolved_via='unresolved'
     *             with no CI linked.  DNS or GLPI inventory may have added the
     *             host in the meantime — worth retrying every hour.
     *
     * Each unique IP is resolved exactly once per pass, then ALL connection rows
     * sharing that IP are updated in a single UPDATE (efficient for servers with
     * hundreds of clients to the same IP).
     *
     * Returns the number of IPs newly matched to a GLPI CI.
     */
    public static function resolveAll(): int {
        global $DB;

        $resolved   = 0;
        $seen_ips   = [];   // deduplicate across both passes

        // ── Pass 1: never attempted ───────────────────────────────────
        $sql1 = "SELECT DISTINCT `remote_addr`, MAX(`remote_hostname`) AS `remote_hostname`
                 FROM `glpi_plugin_netstatconnections_connections`
                 WHERE `remote_addr` != ''
                   AND (`remote_scope` IS NULL OR `remote_scope` = '')
                 GROUP BY `remote_addr`
                 LIMIT 500";

        $r1 = $DB->doQuery($sql1);
        while ($row = $DB->fetchAssoc($r1)) {
            $ip   = $row['remote_addr'];
            $hint = $row['remote_hostname'] ?? '';
            $seen_ips[$ip] = true;
            if (self::_resolveAndUpdateAll($ip, $hint)) $resolved++;
        }

        // ── Pass 2: previously unresolved — retry (DNS may have updated) ─
        $sql2 = "SELECT DISTINCT `remote_addr`, MAX(`remote_hostname`) AS `remote_hostname`
                 FROM `glpi_plugin_netstatconnections_connections`
                 WHERE `remote_addr` != ''
                   AND `connection_status` = 'active'
                   AND `resolved_via` = 'unresolved'
                   AND (`remote_items_id` IS NULL OR `remote_items_id` = 0)
                 GROUP BY `remote_addr`
                 LIMIT 500";

        $r2 = $DB->doQuery($sql2);
        while ($row = $DB->fetchAssoc($r2)) {
            $ip   = $row['remote_addr'];
            if (isset($seen_ips[$ip])) continue;   // already handled in pass 1
            $hint = $row['remote_hostname'] ?? '';
            if (self::_resolveAndUpdateAll($ip, $hint)) $resolved++;
        }

        return $resolved;
    }

    /**
     * Resolve a single IP and update ALL connection rows that share it.
     * Rows already linked to a specific CI (e.g. DatabaseInstance via lock)
     * are left untouched.
     *
     * @return bool  true if the IP was matched to a GLPI CI
     */
    private static function _resolveAndUpdateAll(string $ip, string $hint = ''): bool {
        global $DB;

        $result = self::resolveIP($ip, 0, $hint);

        $update = [
            'remote_scope' => $result['remote_scope'],
            'resolved_via' => $result['resolved_via'],
            'resolved_at'  => new \Glpi\DBAL\QueryExpression('NOW()'),
        ];

        if ($result['remote_items_id']) {
            $update['remote_items_id'] = $result['remote_items_id'];
            $update['remote_itemtype'] = $result['remote_itemtype'];

            // Update all rows for this IP that don't already have a CI linked
            // (preserves DatabaseInstance-level resolutions set by lock.php)
            $DB->update('glpi_plugin_netstatconnections_connections', $update, [
                'remote_addr' => $ip,
                'OR' => [
                    ['remote_items_id' => null],
                    ['remote_items_id' => 0],
                ],
            ]);
        } else {
            // Not matched — update scope/resolved_via only, never clear an existing CI link
            $DB->update('glpi_plugin_netstatconnections_connections', $update, [
                'remote_addr' => $ip,
                'OR' => [
                    ['remote_items_id' => null],
                    ['remote_items_id' => 0],
                ],
            ]);
        }

        return ($result['remote_scope'] === 'internal' && (bool)$result['remote_items_id']);
    }

    /**
     * Resolve a single row.
     */
    public static function resolveRow(array $row): bool {
        global $DB;

        $remote_addr = $row['remote_addr'] ?? '';
        if (empty($remote_addr)) return false;

        // Use stored hostname as a hint before falling back to live DNS lookup
        $hint = $row['remote_hostname'] ?? '';
        $result = self::resolveIP($remote_addr, 0, $hint);

        $update = [
            'remote_scope' => $result['remote_scope'],
            'resolved_via' => $result['resolved_via'],
            'resolved_at'  => new \Glpi\DBAL\QueryExpression('NOW()'),
        ];

        if ($result['remote_items_id']) {
            $update['remote_items_id'] = $result['remote_items_id'];
            $update['remote_itemtype'] = $result['remote_itemtype'];
        }

        $DB->update('glpi_plugin_netstatconnections_connections', $update, ['id' => $row['id']]);

        return ($result['remote_scope'] === 'internal');
    }

    /**
     * Resolve an IP to a CI. Returns result array.
     *
     * @param string $hint  Already-known hostname (e.g. from remote_hostname field) — tried first
     */
    public static function resolveIP(string $ip, int $port = 0, string $hint = ''): array {
        global $DB;

        $default = [
            'remote_scope'    => 'external',
            'resolved_via'    => 'unresolved',
            'remote_items_id' => null,
            'remote_itemtype' => null,
        ];

        if (empty($ip)) return $default;

        // Built-in GLPI CI types we resolve remote endpoints to. Name-matchable
        // types are tried in this order (most specific first) for DNS/hint
        // matches; the same set (minus Cluster, which has no network ports)
        // gates the glpi_ipaddresses → networkport walk below.
        //
        // NOTE: these are GLPI's native asset classes — NOT the GLPI 11 custom
        // "Asset Definition" framework. Each has its own glpi_<type>s table with
        // name + is_deleted, and (except Cluster) can own network ports/IPs.
        static $name_tables = [
            'Cluster'          => 'glpi_clusters',
            'Computer'         => 'glpi_computers',
            'NetworkEquipment' => 'glpi_networkequipments',
            'Printer'          => 'glpi_printers',
            'Phone'            => 'glpi_phones',
            'Peripheral'       => 'glpi_peripherals',
        ];
        // itemtypes acceptable as the owner of a resolved IP (via networkport)
        static $ip_itemtypes = [
            'Computer', 'NetworkEquipment', 'Printer', 'Phone', 'Peripheral',
        ];

        // 1. Try hostname lookup — stored hint first, then live DNS reverse lookup
        $hostnames = array_filter(array_unique([
            $hint,
            self::reverseResolve($ip) ?? '',
        ]));

        foreach ($hostnames as $hostname) {
            // Strip domain
            $short = preg_replace('/\..*$/', '', $hostname);

            foreach ($name_tables as $itemtype => $table) {
                if (!$DB->tableExists($table)) continue;
                $hit = $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => $table,
                    'WHERE'  => ['name' => $short, 'is_deleted' => 0],
                    'LIMIT'  => 1,
                ])->current();

                if ($hit) {
                    return [
                        'remote_scope'    => 'internal',
                        'resolved_via'    => 'dns',
                        'remote_items_id' => (int)$hit['id'],
                        'remote_itemtype' => $itemtype,
                    ];
                }
            }
        }

        // 2. Try IP lookup in glpi_ipaddresses → networkports → Computer
        $ip_row = $DB->request([
            'SELECT' => ['id', 'mainitems_id', 'mainitemtype'],
            'FROM'   => 'glpi_ipaddresses',
            'WHERE'  => ['name' => $ip],
            'LIMIT'  => 1,
        ])->current();

        if ($ip_row) {
            // Walk: ipaddress → networkname → networkport → Computer
            if ($ip_row['mainitemtype'] === 'NetworkName') {
                $nn = $DB->request([
                    'SELECT' => ['items_id', 'itemtype'],
                    'FROM'   => 'glpi_networknames',
                    'WHERE'  => ['id' => (int)$ip_row['mainitems_id']],
                    'LIMIT'  => 1,
                ])->current();

                if ($nn && $nn['itemtype'] === 'NetworkPort') {
                    $np = $DB->request([
                        'SELECT' => ['items_id', 'itemtype'],
                        'FROM'   => 'glpi_networkports',
                        'WHERE'  => ['id' => (int)$nn['items_id']],
                        'LIMIT'  => 1,
                    ])->current();

                    if ($np && in_array($np['itemtype'], $ip_itemtypes, true)) {
                        return [
                            'remote_scope'    => 'internal',
                            'resolved_via'    => 'glpi_ip',
                            'remote_items_id' => (int)$np['items_id'],
                            'remote_itemtype' => $np['itemtype'],
                        ];
                    }
                }
            }
        }

        // 3. Check if it's an RFC1918 address (internal but unresolvable)
        if (self::isPrivate($ip)) {
            $default['remote_scope'] = 'internal';
        }

        return $default;
    }

    private static function reverseResolve(string $ip): ?string {
        $host = @gethostbyaddr($ip);
        return ($host && $host !== $ip) ? $host : null;
    }

    private static function isPrivate(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false
            && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    // ── Pillar 2: DatabaseInstance resolution ─────────────────────────

    /**
     * Given a resolved Computer/Cluster and a service port, check if a
     * DatabaseInstance exists on that host for that port.
     *
     * @param string $itemtype  'Computer' or 'Cluster'
     * @param int    $items_id  The host CI id
     * @param int    $port      The service port (1433, 3306, etc.)
     * @return array|null  ['id' => int, 'name' => string, 'itemtype' => string, 'items_id' => int] or null
     */
    public static function resolveToInstance(string $itemtype, int $items_id, int $port = 0): ?array {
        if ($items_id <= 0) return null;

        global $DB;
        if (!$DB->tableExists('glpi_databaseinstances')) return null;

        // Direct match: instance linked to this Computer or Cluster
        $instance = self::_findInstance($itemtype, $items_id, $port);
        if ($instance) return $instance;

        // AlwaysOn fallback: if resolved to a Computer node, check whether it
        // belongs to a Cluster that owns the DatabaseInstance (listener IP
        // might not have resolved to Cluster, but the node did)
        if ($itemtype === 'Computer' && $DB->tableExists('glpi_clusters_items')) {
            $membership = $DB->request([
                'SELECT' => ['clusters_id'],
                'FROM'   => 'glpi_clusters_items',
                'WHERE'  => ['itemtype' => 'Computer', 'items_id' => $items_id],
                'LIMIT'  => 1,
            ])->current();

            if ($membership) {
                $instance = self::_findInstance('Cluster', (int)$membership['clusters_id'], $port);
                if ($instance) return $instance;
            }
        }

        return null;
    }

    private static function _findInstance(string $itemtype, int $items_id, int $port): ?array {
        global $DB;

        $where = ['itemtype' => $itemtype, 'items_id' => $items_id, 'is_deleted' => 0];

        if ($port > 0) {
            $exact = $DB->request([
                'SELECT' => ['id', 'name', 'itemtype', 'items_id', 'port'],
                'FROM'   => 'glpi_databaseinstances',
                'WHERE'  => array_merge($where, ['port' => (string)$port]),
                'LIMIT'  => 1,
            ])->current();
            if ($exact) return $exact;
        }

        $any = $DB->request([
            'SELECT' => ['id', 'name', 'itemtype', 'items_id', 'port'],
            'FROM'   => 'glpi_databaseinstances',
            'WHERE'  => $where,
            'ORDER'  => ['id ASC'],
            'LIMIT'  => 1,
        ])->current();

        return $any ?: null;
    }

    /**
     * Pillar 3: Build the full impact chain for a DatabaseInstance.
     * If the instance is hosted on a Cluster, returns the chain:
     *   [source_computer] → DatabaseInstance → Cluster
     *
     * @return array  ['instance_id' => int, 'instance_name' => string,
     *                 'host_type' => string, 'host_id' => int] or empty
     */
    public static function resolveInstanceChain(int $instance_id): array {
        global $DB;

        $inst = $DB->request([
            'SELECT' => ['id', 'name', 'itemtype', 'items_id'],
            'FROM'   => 'glpi_databaseinstances',
            'WHERE'  => ['id' => $instance_id, 'is_deleted' => 0],
            'LIMIT'  => 1,
        ])->current();

        if (!$inst) return [];

        return [
            'instance_id'   => (int)$inst['id'],
            'instance_name' => $inst['name'],
            'host_type'     => $inst['itemtype'],   // 'Computer' or 'Cluster'
            'host_id'       => (int)$inst['items_id'],
        ];
    }
}
