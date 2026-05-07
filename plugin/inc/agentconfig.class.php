<?php
/**
 * PluginNetstatconnectionsAgentconfig
 * Manages agent collection configuration — stored in the config key-value table.
 *
 * Global defaults are served to every agent via agentconfig.php.
 * Agents fetch this JSON before each collection cycle and apply the settings
 * instead of (or as a fallback for) a local netstat-collect.ini file.
 */
class PluginNetstatconnectionsAgentconfig {

    const CONFIG_KEY = 'agent_collection';

    const DEFAULTS = [
        'established_only'         => true,
        'skip_ipv6'                => true,
        'skip_loopback'            => true,
        'ephemeral_port_threshold' => 49152,
        'exclude_processes'        => [],
        'exclude_remote_ips'       => [],
        'exclude_remote_ports'     => [],
        'include_only_ips'         => [],
    ];

    /**
     * Get current config (merged with defaults).
     */
    public static function get(): array {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_netstatconnections_config')) {
            return self::DEFAULTS;
        }
        $row = $DB->request([
            'SELECT' => ['value'],
            'FROM'   => 'glpi_plugin_netstatconnections_config',
            'WHERE'  => ['key' => self::CONFIG_KEY],
            'LIMIT'  => 1,
        ])->current();

        if ($row && !empty($row['value'])) {
            $stored = json_decode($row['value'], true);
            if (is_array($stored)) {
                return array_merge(self::DEFAULTS, $stored);
            }
        }
        return self::DEFAULTS;
    }

    /**
     * Save config to the database.
     */
    public static function save(array $config): void {
        global $DB;
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_netstatconnections_config',
            'WHERE' => ['key' => self::CONFIG_KEY],
            'LIMIT' => 1,
        ])->current();

        if ($existing) {
            $DB->update('glpi_plugin_netstatconnections_config',
                ['value' => $json],
                ['key'   => self::CONFIG_KEY]
            );
        } else {
            $DB->insert('glpi_plugin_netstatconnections_config', [
                'key'   => self::CONFIG_KEY,
                'value' => $json,
            ]);
        }
    }

    /**
     * Parse a textarea value (one item per line) into an array.
     */
    public static function textareaToArray(string $text): array {
        $lines = preg_split('/[\r\n]+/', trim($text));
        return array_values(array_filter(array_map('trim', $lines), 'strlen'));
    }

    /**
     * Convert an array to textarea string (one item per line).
     */
    public static function arrayToTextarea(array $arr): string {
        return implode("\n", $arr);
    }
}
