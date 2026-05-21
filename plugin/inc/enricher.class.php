<?php
/**
 * PluginNetstatconnectionsEnricher
 *
 * Pillar 8 — Soft enrichment of unlocked connection rows.
 *
 * Unlike autolock (which requires `auto_lock = 1` on each port policy and
 * commits a hard impact_relation), the enricher just *labels* unlocked rows
 * with the metadata needed for the dependency map to render them.
 *
 * Fills in (only when null/empty — never overwrites existing values):
 *   - service_port      : the well-known port involved (local or remote)
 *   - conn_direction    : 'inbound' or 'outbound'
 *   - impact_direction  : 'impacts' or 'depends' (port policy's auto_direction)
 *
 * For unlocked rows whose remote_addr already resolved to a Computer/Cluster,
 * the dependency-map renderer can now treat them as soft edges (dotted line,
 * lower opacity) — locked rows remain solid edges.
 *
 * Idempotent: re-running the enricher on the same rows is a no-op once they
 * have all three fields populated. Safe to schedule frequently.
 */
class PluginNetstatconnectionsEnricher {

    private static ?array $policy_cache = null;
    private const EPHEMERAL_FLOOR = 49152;

    /**
     * Cron entry point — enrich unlocked active rows in batches.
     *
     * Two strategies per row (mirrors autolock but without committing locks):
     *   1. remote_port matches a known port → outbound, use port's auto_direction
     *   2. local_port matches a known port AND remote_port looks ephemeral → inbound, depends
     *
     * @return int  Number of rows actually updated this run.
     */
    public static function cronSweep(int $limit = 5000): int {
        $policies = self::getPolicies();
        if (empty($policies)) return 0;

        global $DB;
        $table = 'glpi_plugin_netstatconnections_connections';

        // Only touch rows that are missing at least ONE of the enrichment fields.
        // This keeps the working set small once steady-state is reached.
        $iter = $DB->request([
            'SELECT' => [
                'id', 'protocol', 'local_port', 'remote_port',
                'conn_direction', 'impact_direction', 'service_port',
            ],
            'FROM'   => $table,
            'WHERE'  => [
                'is_locked'         => 0,
                'connection_status' => 'active',
                ['NOT' => ['remote_addr' => '']],
                'OR' => [
                    ['service_port'     => null],
                    ['service_port'     => 0],
                    ['impact_direction' => null],
                    ['conn_direction'   => null],
                    ['conn_direction'   => ''],
                ],
            ],
            'LIMIT' => $limit,
        ]);

        $enriched = 0;
        foreach ($iter as $row) {
            $rport = (int)($row['remote_port'] ?? 0);
            $lport = (int)($row['local_port'] ?? 0);
            $proto = strtoupper($row['protocol'] ?? 'TCP');

            $match = self::classify($rport, $lport, $proto, $policies);
            if (!$match) continue;

            $update = [];

            // Only populate fields that are currently empty — never overwrite.
            if (empty($row['service_port']) || (int)$row['service_port'] === 0) {
                $update['service_port'] = $match['service_port'];
            }
            if (empty($row['conn_direction'])) {
                $update['conn_direction'] = $match['conn_direction'];
            }
            if ($row['impact_direction'] === null || $row['impact_direction'] === '') {
                $update['impact_direction'] = $match['impact_direction'];
            }

            if (empty($update)) continue;

            $DB->update($table, $update, ['id' => (int)$row['id']]);
            $enriched++;
        }

        return $enriched;
    }

    /**
     * Pure classifier — returns the enrichment metadata for a (rport, lport, proto)
     * triple, or null if neither side matches a known port policy.
     *
     * @return array{service_port:int, conn_direction:string, impact_direction:string}|null
     */
    public static function classify(int $rport, int $lport, string $proto, array $policies): ?array {
        $proto = strtoupper($proto);

        // Strategy 1: remote_port matches a known port → outbound
        $key = $rport . '_' . $proto;
        if ($rport > 0 && isset($policies[$key])) {
            $policy = $policies[$key];
            return [
                'service_port'     => $rport,
                'conn_direction'   => 'outbound',
                'impact_direction' => $policy['auto_direction'] ?? 'impacts',
            ];
        }

        // Strategy 2: local_port matches a known port AND remote is ephemeral → inbound
        $key = $lport . '_' . $proto;
        if ($lport > 0 && isset($policies[$key]) && self::looksLikeInbound($rport, $lport)) {
            return [
                'service_port'     => $lport,
                'conn_direction'   => 'inbound',
                'impact_direction' => 'depends', // they depend on us
            ];
        }

        return null;
    }

    /**
     * Decide whether (rport, lport) looks like an inbound connection to a service
     * listening on $lport. Uses the same heuristics as the autolock matcher so
     * results are consistent.
     */
    private static function looksLikeInbound(int $rport, int $lport): bool {
        if ($rport >= self::EPHEMERAL_FLOOR) return true;        // classic ephemeral
        if ($rport >= 1024 && $lport < 1024) return true;        // privileged service, high client
        return false;
    }

    /**
     * Load all port policies keyed by "port_protocol" — same shape as autolock's
     * cache, but we load ALL ports (not just auto_lock=1) because enrichment is
     * a soft pass that benefits from every port label admins have curated.
     */
    private static function getPolicies(): array {
        if (self::$policy_cache !== null) return self::$policy_cache;

        global $DB;
        self::$policy_cache = [];

        if (!$DB->tableExists('glpi_plugin_netstatconnections_ports')) {
            return self::$policy_cache;
        }

        $iter = $DB->request([
            'SELECT' => ['port_number', 'protocol', 'auto_direction', 'is_database_port'],
            'FROM'   => 'glpi_plugin_netstatconnections_ports',
            'WHERE'  => ['is_deleted' => 0],
        ]);

        foreach ($iter as $row) {
            $key = (int)$row['port_number'] . '_' . strtoupper($row['protocol']);
            self::$policy_cache[$key] = $row;
        }

        return self::$policy_cache;
    }
}
