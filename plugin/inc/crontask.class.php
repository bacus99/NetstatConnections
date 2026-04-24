<?php
/**
 * PluginNetstatconnectionsCrontask
 * Registers and handles cron tasks:
 *   - NetstatResolveAll: resolve unresolved IPs to GLPI CIs
 *   - NetstatAutoLock:   auto-lock connections matching port policies
 *   - NetstatCleanup:    purge closed connections older than retention period
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
     * Provide task descriptions for GLPI cron UI.
     */
    public static function getTaskDescription(string $name): string {
        switch ($name) {
            case 'NetstatResolveAll':
                return 'Resolve unresolved remote IPs to GLPI computers/clusters';
            case 'NetstatAutoLock':
                return 'Auto-lock connections matching port definition policies';
            case 'NetstatCleanup':
                return 'Purge closed network connections older than retention period (param = days)';
            default:
                return '';
        }
    }
}
