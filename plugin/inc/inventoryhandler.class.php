<?php
/**
 * PluginNetstatconnectionsInventoryhandler
 *
 * PRE_INVENTORY hook — fires after agent auth, BEFORE schema validation.
 *
 * GLPI 11 dispatches the hook via:
 *     $data = Plugin::doHook(Hooks::PRE_INVENTORY, $data);
 * where $data is a stdClass decoded from the agent JSON payload.
 * call_user_func() passes objects by handle (not cloned), so mutations
 * on the object persist even though doHook() ignores return values.
 *
 * The agent Connections.pm injects NETWORK_CONNECTIONS and LISTENING_PORTS
 * into the raw content hash. GLPI schema validation would reject them,
 * so we extract and unset them here before validation runs.
 */
class PluginNetstatconnectionsInventoryhandler {

    /**
     * PRE_INVENTORY callback.
     *
     * @param mixed $data  stdClass — the decoded inventory JSON payload
     * @return mixed       Return $data (doHook ignores it, but good practice)
     */
    public static function preInventory(mixed $data): mixed {
        // Safety: must be an object with a content property
        if (!is_object($data) || !isset($data->content)) {
            return $data;
        }

        $content = $data->content;

        // ── Extract custom sections (lowercase — agent JSON is lowercase) ──
        $connections     = $content->network_connections ?? $content->NETWORK_CONNECTIONS ?? null;
        $listening_ports = $content->listening_ports     ?? $content->LISTENING_PORTS     ?? null;

        $has_connections = !empty($connections);
        $has_listening   = !empty($listening_ports);

        if (!$has_connections && !$has_listening) {
            return $data;
        }

        // ── Remove sections BEFORE schema validation ──────────────────────
        unset(
            $content->network_connections,
            $content->NETWORK_CONNECTIONS,
            $content->listening_ports,
            $content->LISTENING_PORTS
        );

        // ── Resolve Computer ID by hostname ──────────────────────────────
        $hostname = $content->hardware->name
                 ?? $content->HARDWARE->NAME
                 ?? $content->hardware->NAME
                 ?? '';

        // Also try versionprovider for deviceid-based fallback
        if ($hostname === '') {
            $deviceid = $data->deviceid ?? '';
            // deviceid format: hostname-YYYY-MM-DD-HH-MM-SS
            if (preg_match('/^(.+?)-\d{4}-\d{2}-\d{2}/', $deviceid, $m)) {
                $hostname = $m[1];
            }
        }

        if ($hostname === '') {
            // Cannot resolve computer — log and bail
            Toolbox::logInFile(
                'netstatconnections',
                "preInventory: no hostname found in payload, skipping " .
                ($has_connections ? count((array)$connections) : 0) . " connections\n"
            );
            return $data;
        }

        // Look up computer by hostname
        global $DB;
        $computers_id = 0;

        if ($DB && $DB->connected()) {
            $row = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_computers',
                'WHERE'  => ['name' => $hostname, 'is_deleted' => 0],
                'LIMIT'  => 1,
            ])->current();
            $computers_id = (int)($row['id'] ?? 0);
        }

        if ($computers_id <= 0) {
            Toolbox::logInFile(
                'netstatconnections',
                "preInventory: computer not found for hostname '{$hostname}', skipping\n"
            );
            return $data;
        }

        // ── Normalize and persist connections ────────────────────────────
        if ($has_connections) {
            $normalized = [];
            foreach ($connections as $c) {
                // Handle both object (stdClass) and array entries
                $c = (array)$c;
                $normalized[] = [
                    'protocol'          => $c['protocol']          ?? $c['PROTOCOL']          ?? 'TCP',
                    'local_addr'        => $c['local_addr']        ?? $c['LOCAL_ADDR']        ?? '',
                    'local_port'        => (int)($c['local_port']  ?? $c['LOCAL_PORT']        ?? 0),
                    'remote_addr'       => $c['remote_addr']       ?? $c['REMOTE_ADDR']       ?? '',
                    'remote_port'       => (int)($c['remote_port'] ?? $c['REMOTE_PORT']       ?? 0),
                    'remote_hostname'   => $c['remote_hostname']   ?? $c['REMOTE_HOSTNAME']   ?? '',
                    'state'             => $c['state']             ?? $c['STATE']              ?? '',
                    'conn_direction'    => $c['conn_direction']    ?? $c['CONN_DIRECTION']     ?? '',
                    'service_port'      => (int)($c['service_port'] ?? $c['SERVICE_PORT']     ?? 0),
                    'process_name'      => $c['process_name']      ?? $c['PROCESS_NAME']      ?? '',
                    'service_name'      => $c['service_name']      ?? $c['SERVICE_NAME']      ?? '',
                    'created_at'        => $c['created_at']        ?? $c['CREATED_AT']         ?? '',
                    'offload_state'     => $c['offload_state']     ?? $c['OFFLOAD_STATE']      ?? '',
                    'applied_setting'   => $c['applied_setting']   ?? $c['APPLIED_SETTING']    ?? '',
                    'collection_method' => $c['collection_method'] ?? $c['COLLECTION_METHOD']  ?? '',
                ];
            }

            Toolbox::logInFile(
                'netstatconnections',
                "preInventory: processing " . count($normalized) .
                " connections for computer #{$computers_id} ({$hostname})\n"
            );

            PluginNetstatconnectionsConnection::handleInventory(
                $computers_id,
                $normalized,
                date('Y-m-d H:i:s')
            );
        }

        // ── Persist listening ports (future use) ─────────────────────────
        if ($has_listening) {
            Toolbox::logInFile(
                'netstatconnections',
                "preInventory: stripped " . count((array)$listening_ports) .
                " listening_ports for computer #{$computers_id}\n"
            );
            // TODO: persist listening ports if needed
        }

        return $data;
    }
}
