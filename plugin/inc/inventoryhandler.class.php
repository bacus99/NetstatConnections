<?php
/**
 * PluginNetstatconnectionsInventoryhandler
 * Handles inventory data injection from the GLPI Agent.
 * Called via the inventory_injection hook.
 */
class PluginNetstatconnectionsInventoryhandler {

    /**
     * Process incoming inventory data.
     * Called with the Computer ID, the raw inventory content, and an agent reference.
     */
    public static function handleInventory($params): void {
        if (!isset($params['items_id']) || !isset($params['inventory'])) return;

        $computers_id = (int)$params['items_id'];
        if ($computers_id <= 0) return;

        $inventory = $params['inventory'];

        // Look for NETWORK_CONNECTIONS section
        $connections = $inventory['NETWORK_CONNECTIONS'] ?? $inventory['network_connections'] ?? [];
        if (empty($connections)) return;

        $collected_at = date('Y-m-d H:i:s');

        PluginNetstatconnectionsConnection::handleInventory(
            $computers_id,
            $connections,
            $collected_at
        );
    }
}
