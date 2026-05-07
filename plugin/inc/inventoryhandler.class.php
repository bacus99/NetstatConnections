<?php
/**
 * PluginNetstatconnectionsInventoryhandler
 * Extracts NETWORK_CONNECTIONS from inventory payload via PRE_INVENTORY hook.
 *
 * The GLPI Agent Connections.pm module injects a NETWORK_CONNECTIONS section
 * into the standard inventory payload using $inventory->addEntry().  This hook
 * fires after agent authentication but before GLPI schema validation, so we can
 * pull out the custom section (preventing a schema-rejection error) and persist
 * the rows ourselves.
 */
class PluginNetstatconnectionsInventoryhandler {

    public static function preInventory(array &$params): void {
        $inv = &$params['inventory'];

        // The agent serialises section names in uppercase JSON keys.
        $connections = $inv['content']['NETWORK_CONNECTIONS']
                    ?? $inv['content']['network_connections']
                    ?? [];

        if (empty($connections)) {
            return;
        }

        // Remove the section so GLPI schema validation does not reject it.
        unset($inv['content']['NETWORK_CONNECTIONS'], $inv['content']['network_connections']);

        // Resolve the Computer ID.
        $computers_id = (int)($params['items_id'] ?? 0);

        if ($computers_id <= 0) {
            // Fallback: look up by hostname from the inventory hardware section.
            $hostname = $inv['content']['HARDWARE']['NAME']
                     ?? $inv['content']['hardware']['name']
                     ?? '';
            if ($hostname !== '') {
                global $DB;
                $row = $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => 'glpi_computers',
                    'WHERE'  => ['name' => $hostname, 'is_deleted' => 0],
                    'LIMIT'  => 1,
                ])->current();
                $computers_id = (int)($row['id'] ?? 0);
            }
        }

        if ($computers_id <= 0) {
            return;
        }

        // Normalize uppercase agent keys to lowercase plugin keys.
        $normalized = [];
        foreach ($connections as $c) {
            $normalized[] = [
                'protocol'        => $c['PROTOCOL']        ?? $c['protocol']        ?? 'TCP',
                'local_addr'      => $c['LOCAL_ADDR']      ?? $c['local_addr']      ?? '',
                'local_port'      => (int)($c['LOCAL_PORT']  ?? $c['local_port']  ?? 0),
                'remote_addr'     => $c['REMOTE_ADDR']     ?? $c['remote_addr']     ?? '',
                'remote_port'     => (int)($c['REMOTE_PORT'] ?? $c['remote_port'] ?? 0),
                'remote_hostname' => $c['REMOTE_HOSTNAME'] ?? $c['remote_hostname'] ?? '',
                'state'           => $c['STATE']           ?? $c['state']           ?? '',
                'conn_direction'  => $c['CONN_DIRECTION']  ?? $c['conn_direction']  ?? '',
                'service_port'    => (int)($c['SERVICE_PORT'] ?? $c['service_port'] ?? 0),
                'process_name'    => $c['PROCESS_NAME']    ?? $c['process_name']    ?? '',
                'pid'             => (int)($c['PID']         ?? $c['pid']         ?? 0),
                'created_at'      => $c['CREATED_AT']      ?? $c['created_at']      ?? '',
                'offload_state'   => $c['OFFLOAD_STATE']   ?? $c['offload_state']   ?? '',
                'applied_setting' => $c['APPLIED_SETTING'] ?? $c['applied_setting'] ?? '',
            ];
        }

        PluginNetstatconnectionsConnection::handleInventory(
            $computers_id,
            $normalized,
            date('Y-m-d H:i:s')
        );
    }
}
