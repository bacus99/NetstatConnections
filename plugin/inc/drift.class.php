<?php
/**
 * PluginNetstatconnectionsDrift
 *
 * Topology drift detection — records when an observed dependency first appears
 * or disappears, building a persistent change log over time.
 *
 * Built entirely on the edge ledger (v2.4.0):
 *   - APPEARED    = an edge whose `first_seen` is newer than the last drift run
 *                   (first_seen is stamped once at INSERT and never changes, so
 *                   each new dependency is caught exactly once).
 *   - DISAPPEARED = an edge whose `closed_at` is newer than the last drift run
 *                   (the lifecycle cron stamps closed_at when it flips an edge
 *                   active → closed; push.php clears it on reactivation).
 *
 * Only edges RESOLVED to a CI (remote_items_id > 0) are logged, so drift is
 * CI-to-CI signal ("ServerA started talking to DB-X") rather than noise from
 * transient external chatter.
 *
 * Identity fields are snapshotted into the drift row, so an event survives even
 * after the originating connection is purged by the cleanup cron.
 */
class PluginNetstatconnectionsDrift {

    const CONN_TABLE   = 'glpi_plugin_netstatconnections_connections';
    const DRIFT_TABLE  = 'glpi_plugin_netstatconnections_drift';
    const CONFIG_TABLE = 'glpi_plugin_netstatconnections_config';
    const LAST_RUN_KEY = 'drift_last_run';

    /**
     * Detect drift since the last run and append events. Returns events logged.
     */
    public static function detect(): int {
        global $DB;

        if (!$DB->tableExists(self::CONN_TABLE) || !$DB->tableExists(self::DRIFT_TABLE)) {
            return 0;
        }

        // Capture the run boundary from the DB clock (same clock as first_seen /
        // closed_at), so app↔DB skew can't make us miss or double-count.
        $now_row    = $DB->doQuery("SELECT NOW() AS n");
        $run_start  = $DB->fetchAssoc($now_row)['n'] ?? null;
        if (!$run_start) return 0;

        $last_run = self::getConfig(self::LAST_RUN_KEY);
        if ($last_run === null || $last_run === '') {
            // First ever run: establish a baseline and log nothing — otherwise
            // every pre-existing edge would flood the log as a false "appeared".
            self::setConfig(self::LAST_RUN_KEY, $run_start);
            return 0;
        }

        $logged = 0;
        $cols   = "computers_id, edge_key, protocol, service_port, conn_direction,
                   remote_addr, remote_hostname, remote_itemtype, remote_items_id, process_name";

        // ── APPEARED ─────────────────────────────────────────────────────
        $q = $DB->doQuery(
            "SELECT {$cols}
             FROM `" . self::CONN_TABLE . "`
             WHERE `connection_status` = 'active'
               AND `remote_items_id` > 0
               AND `first_seen` IS NOT NULL
               AND `first_seen` > " . $DB->quote($last_run) . "
               AND `first_seen` <= " . $DB->quote($run_start) . "
             LIMIT 5000"
        );
        while ($r = $DB->fetchAssoc($q)) {
            $logged += self::logEvent('appeared', $r);
        }

        // ── DISAPPEARED ──────────────────────────────────────────────────
        $q = $DB->doQuery(
            "SELECT {$cols}
             FROM `" . self::CONN_TABLE . "`
             WHERE `connection_status` = 'closed'
               AND `remote_items_id` > 0
               AND `closed_at` IS NOT NULL
               AND `closed_at` > " . $DB->quote($last_run) . "
               AND `closed_at` <= " . $DB->quote($run_start) . "
             LIMIT 5000"
        );
        while ($r = $DB->fetchAssoc($q)) {
            $logged += self::logEvent('disappeared', $r);
        }

        // ── REAPPEARED ───────────────────────────────────────────────────
        // A previously-closed edge that push.php reactivated (closed → active).
        // `reopened_at` is stamped only on that transition, so — like first_seen
        // for 'appeared' — each comeback is caught exactly once.
        if ($DB->fieldExists(self::CONN_TABLE, 'reopened_at')) {
            $q = $DB->doQuery(
                "SELECT {$cols}
                 FROM `" . self::CONN_TABLE . "`
                 WHERE `connection_status` = 'active'
                   AND `remote_items_id` > 0
                   AND `reopened_at` IS NOT NULL
                   AND `reopened_at` > " . $DB->quote($last_run) . "
                   AND `reopened_at` <= " . $DB->quote($run_start) . "
                 LIMIT 5000"
            );
            while ($r = $DB->fetchAssoc($q)) {
                $logged += self::logEvent('reappeared', $r);
            }
        }

        self::setConfig(self::LAST_RUN_KEY, $run_start);
        return $logged;
    }

    private static function logEvent(string $type, array $r): int {
        global $DB;
        $DB->insert(self::DRIFT_TABLE, [
            'computers_id'    => (int)($r['computers_id'] ?? 0),
            'edge_key'        => $r['edge_key'] ?? null,
            'event_type'      => $type,
            'protocol'        => (string)($r['protocol'] ?? ''),
            'service_port'    => isset($r['service_port']) ? (int)$r['service_port'] : null,
            'conn_direction'  => $r['conn_direction'] ?? null,
            'remote_addr'     => (string)($r['remote_addr'] ?? ''),
            'remote_hostname' => $r['remote_hostname'] ?? null,
            'remote_itemtype' => $r['remote_itemtype'] ?? null,
            'remote_items_id' => isset($r['remote_items_id']) ? (int)$r['remote_items_id'] : null,
            'process_name'    => (string)($r['process_name'] ?? ''),
            'detected_at'     => new \Glpi\DBAL\QueryExpression('NOW()'),
        ]);
        return 1;
    }

    /**
     * Purge drift events older than $days. SQL-native cutoff (no PHP date math).
     */
    public static function purgeOld(int $days): int {
        global $DB;
        if ($days <= 0 || !$DB->tableExists(self::DRIFT_TABLE)) return 0;
        $days = max(1, $days);
        try {
            $DB->doQuery("DELETE FROM `" . self::DRIFT_TABLE . "`
                          WHERE `detected_at` < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
        } catch (\Throwable $e) { /* ignore */ }
        return 1;
    }

    // ── Config helpers (key-value table) ──────────────────────────────────

    private static function getConfig(string $key): ?string {
        global $DB;
        if (!$DB->tableExists(self::CONFIG_TABLE)) return null;
        $row = $DB->request([
            'SELECT' => ['value'],
            'FROM'   => self::CONFIG_TABLE,
            'WHERE'  => ['key' => $key],
            'LIMIT'  => 1,
        ])->current();
        return $row ? (string)$row['value'] : null;
    }

    private static function setConfig(string $key, string $val): void {
        global $DB;
        if (!$DB->tableExists(self::CONFIG_TABLE)) return;
        $existing = $DB->request([
            'FROM'  => self::CONFIG_TABLE,
            'WHERE' => ['key' => $key],
            'LIMIT' => 1,
        ])->current();
        if ($existing) {
            $DB->update(self::CONFIG_TABLE, ['value' => $val], ['key' => $key]);
        } else {
            $DB->insert(self::CONFIG_TABLE, ['key' => $key, 'value' => $val]);
        }
    }
}
