<?php
/**
 * PluginNetstatconnectionsResolver
 * Resolves remote IP addresses to GLPI Computer / Cluster CIs.
 *
 * v1.1.2 — resolved_via uses 'unresolved' (not 'none') to match ENUM.
 */
class PluginNetstatconnectionsResolver {

    /**
     * Resolve all unresolved connections (cron entry point).
     */
    public static function resolveAll(): int {
        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_netstatconnections_connections',
            'WHERE' => [
                'OR' => [
                    ['remote_scope' => ''],
                    ['remote_scope' => null],
                ],
            ],
            'LIMIT' => 2000,
        ]);

        $resolved = 0;
        foreach ($iter as $row) {
            if (self::resolveRow($row)) {
                $resolved++;
            }
        }
        return $resolved;
    }

    /**
     * Resolve a single row.
     */
    public static function resolveRow(array $row): bool {
        global $DB;

        $remote_addr = $row['remote_addr'] ?? '';
        if (empty($remote_addr)) return false;

        $result = self::resolveIP($remote_addr);

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
     */
    public static function resolveIP(string $ip): array {
        global $DB;

        $default = [
            'remote_scope'    => 'external',
            'resolved_via'    => 'unresolved',
            'remote_items_id' => null,
            'remote_itemtype' => null,
        ];

        if (empty($ip)) return $default;

        // 1. Try hostname reverse lookup in GLPI computers
        $hostname = self::reverseResolve($ip);
        if ($hostname) {
            // Strip domain
            $short = preg_replace('/\..*$/', '', $hostname);

            // Check clusters first
            $cluster = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_clusters',
                'WHERE'  => ['name' => $short, 'is_deleted' => 0],
                'LIMIT'  => 1,
            ])->current();

            if ($cluster) {
                return [
                    'remote_scope'    => 'internal',
                    'resolved_via'    => 'dns',
                    'remote_items_id' => (int)$cluster['id'],
                    'remote_itemtype' => 'Cluster',
                ];
            }

            // Check computers by name
            $computer = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_computers',
                'WHERE'  => ['name' => $short, 'is_deleted' => 0],
                'LIMIT'  => 1,
            ])->current();

            if ($computer) {
                return [
                    'remote_scope'    => 'internal',
                    'resolved_via'    => 'dns',
                    'remote_items_id' => (int)$computer['id'],
                    'remote_itemtype' => 'Computer',
                ];
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

                    if ($np && in_array($np['itemtype'], ['Computer', 'Cluster'])) {
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
        global $DB;

        if ($items_id <= 0) return null;
        if (!$DB->tableExists('glpi_databaseinstances')) return null;

        $where = [
            'itemtype'   => $itemtype,
            'items_id'   => $items_id,
            'is_deleted' => 0,
        ];

        // If port is known, try exact match first
        if ($port > 0) {
            $exact = $DB->request(array_merge(
                ['SELECT' => ['id', 'name', 'itemtype', 'items_id', 'port'], 'FROM' => 'glpi_databaseinstances', 'LIMIT' => 1],
                ['WHERE' => array_merge($where, ['port' => (string)$port])]
            ))->current();
            if ($exact) {
                return $exact;
            }
        }

        // Fallback: any instance on this host (many SQL Server installs have empty port)
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
