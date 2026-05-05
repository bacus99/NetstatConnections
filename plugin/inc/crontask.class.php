<?php
/**
 * PluginNetstatconnectionsCrontask
 *
 * Pillar 7 — Cron housekeeping
 *
 * Three scheduled tasks:
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
    }

    public static function unregisterCronTasks(): void {
        $cron = new CronTask();
        foreach (['NetstatResolveAll', 'NetstatAutoLock', 'NetstatCleanup'] as $name) {
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
     * Cron: Purge closed connections older than retention period.
     * param = number of days to retain (0 = keep forever, skip purge).
     */
    public static function cronNetstatCleanup(CronTask $task): int {
        $days = (int)($task->fields['param'] ?? self::CLOSED_RETENTION_DAYS);
        if ($days <= 0) return 0; // 0 = keep forever

        global $DB;

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Only delete unlocked closed connections past retention
        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_netstatconnections_connections',
            'WHERE'  => [
                'connection_status' => 'closed',
                'is_locked'         => 0,
                ['last_seen' => ['<', $cutoff]],
            ],
            'LIMIT'  => 5000,
        ]);

        $deleted = 0;
        $ids = [];
        foreach ($iter as $row) {
            $ids[] = (int)$row['id'];
        }

        if (!empty($ids)) {
            $DB->delete('glpi_plugin_netstatconnections_connections', [
                'id' => $ids,
            ]);
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

            case 'NetstatCleanup':
                return [
                    'description' => __('Purge closed unlocked connections older than retention period', 'netstatconnections'),
                    'parameter'   => __('Retention in days (0 = keep forever)', 'netstatconnections'),
                ];
        }
        return [];
    }
}
