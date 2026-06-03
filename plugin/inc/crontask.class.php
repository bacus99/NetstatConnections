<?php
/**
 * PluginNetstatconnectionsCrontask
 *
 * Pillar 7 — Cron housekeeping
 *
 * Six scheduled tasks (NetstatResolveAll, NetstatAutoLock, NetstatEnrich,
 * NetstatLifecycle, NetstatCleanup, NetstatDrift):
 *
 *   NetstatLifecycle  (hourly) — Transition active → closed for connections whose
 *                                last_seen is older than param hours (default 72).
 *                                Default 72h = 3 × default agent push cycle (24h),
 *                                which absorbs cron jitter, push delays, and brief
 *                                outages so we don't flap on every push.
 *                                Also handles stale agents: if ALL active connections
 *                                for a computer have last_seen > 7 days, marks them
 *                                all closed in one sweep.
 *
 *   NetstatEnrich     (hourly) — Soft enrichment of unlocked rows: populate
 *                                service_port, conn_direction, impact_direction
 *                                based on port definitions. Never overwrites
 *                                existing values. Drives the dependency map fill-in
 *                                without requiring admins to manually lock rows.
 *
 *   NetstatCleanup    (daily)  — Purge closed unlocked connections older than
 *                                X days (configurable via cron param field).
 *                                Set param = 0 to keep forever.
 *
 *   NetstatResolveAll (hourly) — Re-resolve IPs to GLPI CIs.
 *                                Pass 1: rows never attempted (remote_scope null/empty).
 *                                Pass 2: rows previously marked 'unresolved' — DNS or
 *                                        GLPI inventory may have added the host since.
 *                                Deduplicates by remote_addr (resolves each IP once,
 *                                updates all rows sharing that IP in one shot).
 *
 *   NetstatAutoLock   (hourly) — Re-run auto-lock sweep on all unlocked active
 *                                connections. Catches connections added before a port
 *                                policy existed — when a new policy is created, the
 *                                next cron run locks all matching pre-existing rows.
 */
class PluginNetstatconnectionsCrontask {

    /** @var int Default retention for closed connections (days) */
    const CLOSED_RETENTION_DAYS = 30;

    /**
     * Default stale threshold for lifecycle transition (hours).
     *
     * Must be SIGNIFICANTLY LARGER than the agent's inventory push cycle to avoid
     * flapping. GLPI agents default to 24h push cycles, so we use 72h (3 cycles)
     * to absorb cron jitter, push delays, weekend maintenance windows, and brief
     * network outages. If your agents push more frequently, you can lower this
     * via the cron task's "param" field in Setup → Automatic Actions.
     *
     * Rule of thumb: param ≥ 3 × agent_push_interval.
     *
     * @var int
     */
    const LIFECYCLE_STALE_HOURS = 72;

    /** @var int Threshold for stale-agent detection (days without any push) */
    const STALE_AGENT_DAYS = 7;

    /** @var int Default retention for drift events (days; 0 = keep forever) */
    const DRIFT_RETENTION_DAYS = 90;

    public static function registerCronTasks(): void {
        $cron = new CronTask();

        if (!$cron->getFromDBbyName('PluginNetstatconnectionsCrontask', 'NetstatResolveAll')) {
            CronTask::register(
                'PluginNetstatconnectionsCrontask',
                'NetstatResolveAll',
                3600, // hourly
                [
                    'mode'  => CronTask::MODE_INTERNAL,
                    'state' => CronTask::STATE_WAITING,
                    'param' => 0,
                ]
            );
        }

        if (!$cron->getFromDBbyName('PluginNetstatconnectionsCrontask', 'NetstatAutoLock')) {
            CronTask::register(
                'PluginNetstatconnectionsCrontask',
                'NetstatAutoLock',
                3600, // hourly
                [
                    'mode'  => CronTask::MODE_INTERNAL,
                    'state' => CronTask::STATE_WAITING,
                    'param' => 0,
                ]
            );
        }

        if (!$cron->getFromDBbyName('PluginNetstatconnectionsCrontask', 'NetstatEnrich')) {
            CronTask::register(
                'PluginNetstatconnectionsCrontask',
                'NetstatEnrich',
                3600, // hourly
                [
                    'mode'  => CronTask::MODE_INTERNAL,
                    'state' => CronTask::STATE_WAITING,
                    'param' => 0,
                ]
            );
        }

        if (!$cron->getFromDBbyName('PluginNetstatconnectionsCrontask', 'NetstatLifecycle')) {
            CronTask::register(
                'PluginNetstatconnectionsCrontask',
                'NetstatLifecycle',
                3600, // hourly
                [
                    'mode'  => CronTask::MODE_INTERNAL,
                    'state' => CronTask::STATE_WAITING,
                    'param' => self::LIFECYCLE_STALE_HOURS,
                ]
            );
        }

        if (!$cron->getFromDBbyName('PluginNetstatconnectionsCrontask', 'NetstatCleanup')) {
            CronTask::register(
                'PluginNetstatconnectionsCrontask',
                'NetstatCleanup',
                86400, // daily
                [
                    'mode'  => CronTask::MODE_INTERNAL,
                    'state' => CronTask::STATE_WAITING,
                    'param' => self::CLOSED_RETENTION_DAYS,
                ]
            );
        }

        if (!$cron->getFromDBbyName('PluginNetstatconnectionsCrontask', 'NetstatDrift')) {
            CronTask::register(
                'PluginNetstatconnectionsCrontask',
                'NetstatDrift',
                3600, // hourly
                [
                    'mode'  => CronTask::MODE_INTERNAL,
                    'state' => CronTask::STATE_WAITING,
                    'param' => self::DRIFT_RETENTION_DAYS,
                ]
            );
        }
    }

    public static function unregisterCronTasks(): void {
        $cron = new CronTask();
        foreach (['NetstatResolveAll', 'NetstatAutoLock', 'NetstatEnrich', 'NetstatLifecycle', 'NetstatCleanup', 'NetstatDrift'] as $name) {
            if ($cron->getFromDBbyName('PluginNetstatconnectionsCrontask', $name)) {
                $cron->delete(['id' => $cron->getID()]);
            }
        }
    }

    /**
     * Cron: Resolve IPs → runs BEFORE auto-lock.
     */
    public static function cronNetstatResolveAll(CronTask $task): int {
        $resolved = PluginNetstatconnectionsResolver::resolveAll();
        $task->addVolume($resolved);
        return ($resolved > 0) ? 1 : 0;
    }

    /**
     * Cron: Auto-lock sweep.
     */
    public static function cronNetstatAutoLock(CronTask $task): int {
        $locked = PluginNetstatconnectionsAutolock::cronSweep();
        $task->addVolume($locked);
        return ($locked > 0) ? 1 : 0;
    }

    /**
     * Cron: Soft enrichment of unlocked rows — populate service_port,
     * conn_direction, impact_direction from port definitions. Never overwrites
     * existing values. Feeds the dependency-map view so unlocked connections
     * still render as soft edges.
     */
    public static function cronNetstatEnrich(CronTask $task): int {
        $enriched = PluginNetstatconnectionsEnricher::cronSweep();
        $task->addVolume($enriched);
        return ($enriched > 0) ? 1 : 0;
    }

    /**
     * Cron: Topology drift detection. Records 'appeared' / 'disappeared' events
     * since the last run, then purges drift events older than param days.
     * param = drift-event retention in days (0 = keep forever).
     *
     * Should run AFTER NetstatLifecycle (which stamps closed_at) so disappearances
     * are detected in the same cycle they're marked closed.
     */
    public static function cronNetstatDrift(CronTask $task): int {
        $logged = PluginNetstatconnectionsDrift::detect();

        $days = (int)($task->fields['param'] ?? self::DRIFT_RETENTION_DAYS);
        if ($days > 0) {
            PluginNetstatconnectionsDrift::purgeOld($days);
        }

        $task->addVolume($logged);
        return ($logged > 0) ? 1 : 0;
    }

    /**
     * Cron: Lifecycle — transition active → closed for stale connections.
     *
     * Two passes:
     *   1. Individual stale connections: any active unlocked row whose last_seen
     *      is older than `param` hours → mark closed.
     *   2. Stale agents: if a computer has NO active connections with last_seen
     *      within the last 7 days, mark ALL its remaining active unlocked rows
     *      closed (the agent has stopped reporting entirely).
     *
     * param = hours threshold (default 72 = 3× the 24h agent push cycle).
     *         Should be ≥ 3 × agent_push_interval to avoid flapping.
     *         0 = disabled.
     */
    public static function cronNetstatLifecycle(CronTask $task): int {
        $hours = (int)($task->fields['param'] ?? self::LIFECYCLE_STALE_HOURS);
        if ($hours <= 0) return 0; // 0 = disabled

        // Defensive cast — param could theoretically be non-numeric
        $hours = max(1, (int)$hours);
        $stale_days = max(1, (int)self::STALE_AGENT_DAYS);

        global $DB;

        $table  = 'glpi_plugin_netstatconnections_connections';
        $closed = 0;

        // ── Pass 1: Individual stale connections ────────────────────────
        // Connections still marked active whose last_seen is older than
        // the configured threshold — the agent pushed newer data but this
        // particular connection was no longer present (push.php only
        // DELETEs+INSERTs non-locked rows; locked rows that vanish from
        // the agent's netstat stay active until this cron catches them).
        //
        // Uses SQL-native DATE_SUB so the cutoff is always a valid
        // datetime no matter what PHP's strtotime / locale settings do.
        $select_sql = "SELECT `id`
            FROM `{$table}`
            WHERE `connection_status` = 'active'
              AND `is_locked`         = 0
              AND IFNULL(`last_seen` + 0, 0) > 0
              AND `last_seen` < DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
            LIMIT 10000";

        $result = $DB->doQuery($select_sql);
        $ids = [];
        while ($row = $DB->fetchAssoc($result)) {
            $ids[] = (int)$row['id'];
        }

        if (!empty($ids)) {
            // Batch update in chunks of 1000 to avoid huge IN clauses.
            // Stamp closed_at = NOW() so drift detection can find "disappeared
            // since last run" (only stamp rows not already carrying a closed_at).
            foreach (array_chunk($ids, 1000) as $chunk) {
                $DB->update($table, [
                    'connection_status' => 'closed',
                    'closed_at'         => new \Glpi\DBAL\QueryExpression('NOW()'),
                ], [
                    'id' => $chunk,
                ]);
            }
            $closed += count($ids);
        }

        // ── Pass 2: Stale agents ────────────────────────────────────────
        // Computers where the NEWEST last_seen across all active rows is
        // older than STALE_AGENT_DAYS — the agent has gone silent.
        // Mark all their active unlocked connections closed.
        // Filter out NULL and zero-date last_seen values BEFORE aggregation so
        // MAX() never reads invalid data. The `IFNULL(col+0, 0) > 0` idiom
        // accepts only valid datetimes via numeric coercion.
        $stale_sql = "SELECT `computers_id`
            FROM `{$table}`
            WHERE `connection_status` = 'active'
              AND `is_locked`         = 0
              AND IFNULL(`last_seen` + 0, 0) > 0
            GROUP BY `computers_id`
            HAVING MAX(`last_seen`) < DATE_SUB(NOW(), INTERVAL {$stale_days} DAY)
            LIMIT 500";

        $result = $DB->doQuery($stale_sql);
        $stale_ids = [];
        while ($row = $DB->fetchAssoc($result)) {
            $stale_ids[] = (int)$row['computers_id'];
        }

        if (!empty($stale_ids)) {
            foreach ($stale_ids as $cid) {
                $DB->update($table, [
                    'connection_status' => 'closed',
                    'closed_at'         => new \Glpi\DBAL\QueryExpression('NOW()'),
                ], [
                    'computers_id'      => $cid,
                    'connection_status' => 'active',
                    'is_locked'         => 0,
                ]);
            }
            // Count how many were affected (approximate — good enough for volume)
            $closed += count($stale_ids); // logged as "X stale computers processed"
        }

        $task->addVolume($closed);
        return ($closed > 0) ? 1 : 0;
    }

    /**
     * Cron: Purge closed connections older than retention period.
     * param = number of days to retain (0 = keep forever, skip purge).
     */
    public static function cronNetstatCleanup(CronTask $task): int {
        $days = (int)($task->fields['param'] ?? self::CLOSED_RETENTION_DAYS);
        if ($days <= 0) return 0; // 0 = keep forever
        $days = max(1, (int)$days);

        global $DB;
        $table = 'glpi_plugin_netstatconnections_connections';

        // SQL-native cutoff so no PHP date math can produce an empty literal.
        $select_sql = "SELECT `id`
            FROM `{$table}`
            WHERE `connection_status` = 'closed'
              AND `is_locked`         = 0
              AND IFNULL(`last_seen` + 0, 0) > 0
              AND `last_seen` < DATE_SUB(NOW(), INTERVAL {$days} DAY)
            LIMIT 5000";

        $result = $DB->doQuery($select_sql);
        $ids = [];
        while ($row = $DB->fetchAssoc($result)) {
            $ids[] = (int)$row['id'];
        }

        $deleted = 0;
        if (!empty($ids)) {
            $DB->delete($table, ['id' => $ids]);
            $deleted = count($ids);
        }

        $task->addVolume($deleted);
        return ($deleted > 0) ? 1 : 0;
    }

    /**
     * GLPI cron UI metadata — called by CronTask when rendering the task list.
     * Must return ['description' => '...'] and optionally ['parameter' => '...']
     * for tasks that use the param field.
     */
    public static function cronInfo(string $name): array {
        switch ($name) {
            case 'NetstatResolveAll':
                return [
                    'description' => __('Re-resolve remote IPs to GLPI CIs (retries previously unresolved internal IPs)', 'netstatconnections'),
                ];

            case 'NetstatAutoLock':
                return [
                    'description' => __('Auto-lock connections matching port policies (catches pre-policy connections)', 'netstatconnections'),
                ];

            case 'NetstatEnrich':
                return [
                    'description' => __('Soft enrichment: populate service_port / conn_direction / impact_direction on unlocked rows from port definitions (drives dependency map fill-in)', 'netstatconnections'),
                ];

            case 'NetstatDrift':
                return [
                    'description' => __('Topology drift: log when CI-to-CI dependencies appear or disappear, building a change history', 'netstatconnections'),
                    'parameter'   => __('Drift-event retention in days (0 = keep forever)', 'netstatconnections'),
                ];

            case 'NetstatLifecycle':
                return [
                    'description' => __('Mark stale active connections as closed (agents that stopped reporting)', 'netstatconnections'),
                    'parameter'   => __('Hours since last_seen before marking closed — set ≥ 3× your agent push interval (default 72 = 3×24h). 0 = disabled.', 'netstatconnections'),
                ];

            case 'NetstatCleanup':
                return [
                    'description' => __('Purge closed unlocked connections older than retention period', 'netstatconnections'),
                    'parameter'   => __('Retention in days (0 = keep forever)', 'netstatconnections'),
                ];
        }
        return [];
    }
}
