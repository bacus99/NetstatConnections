<?php
/**
 * Plugin: netstatconnections v1.3.0
 * Network Connections — lifecycle push, auto-lock, Impact Analysis
 *
 * v1.3.0 — Pillar 1: Connection lifecycle via bulk push endpoint
 *   - push.php: hardened auth (Session-Token + App-Token validation)
 *   - handleInventory(): $seen_ids init fix, conn_direction in UPDATE/INSERT
 *   - Agent: bulk push mode (single POST replaces delete+insert loop)
 *   - Vanished connections marked 'closed' instead of deleted
 */

define('PLUGIN_NETSTATCONNECTIONS_VERSION', '1.3.0');
define('PLUGIN_NETSTATCONNECTIONS_MIN_GLPI', '11.0.0');
define('PLUGIN_NETSTATCONNECTIONS_MAX_GLPI', '12.0.0');

// Autoload all plugin classes from inc/
spl_autoload_register(function (string $class): void {
    if (strpos($class, 'PluginNetstatconnections') !== 0) {
        return;
    }
    $base = strtolower(str_replace('PluginNetstatconnections', '', $class));
    $file = __DIR__ . "/inc/{$base}.class.php";
    if (file_exists($file)) {
        require_once $file;
    }
});

// Include hook.php for install/uninstall
include_once __DIR__ . '/hook.php';

// ── Hooks ─────────────────────────────────────────────────────────────────────

function plugin_init_netstatconnections(): void {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['netstatconnections'] = true;

    // Register classes
    Plugin::registerClass('PluginNetstatconnectionsConnection', ['addtabon' => ['Computer']]);
    Plugin::registerClass('PluginNetstatconnectionsPort');

    // Config page link
    $PLUGIN_HOOKS['config_page']['netstatconnections'] = 'front/port.php';

    // Register under Setup → Dropdowns
    $PLUGIN_HOOKS['menu_toadd']['netstatconnections'] = ['config' => 'PluginNetstatconnectionsPort'];

    // Setup → Dropdowns
    $PLUGIN_HOOKS['plugin_dropdown_tabs']['netstatconnections'] = [
        'PluginNetstatconnectionsPort'
    ];

    // Inventory handler — process netstat data on inventory push
    $PLUGIN_HOOKS['inventory_injection']['netstatconnections'] = [
        'PluginNetstatconnectionsInventoryhandler',
        'handleInventory'
    ];
}

function plugin_version_netstatconnections(): array {
    return [
        'name'         => 'Network Connections',
        'version'      => PLUGIN_NETSTATCONNECTIONS_VERSION,
        'author'       => 'Custom',
        'license'      => 'GPLv3',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_NETSTATCONNECTIONS_MIN_GLPI,
                'max' => PLUGIN_NETSTATCONNECTIONS_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_netstatconnections_check_prerequisites(): bool {
    if (version_compare(GLPI_VERSION, PLUGIN_NETSTATCONNECTIONS_MIN_GLPI, 'lt') ||
        version_compare(GLPI_VERSION, PLUGIN_NETSTATCONNECTIONS_MAX_GLPI, 'ge')) {
        echo sprintf(
            __('This plugin requires GLPI >= %s and < %s', 'netstatconnections'),
            PLUGIN_NETSTATCONNECTIONS_MIN_GLPI,
            PLUGIN_NETSTATCONNECTIONS_MAX_GLPI
        );
        return false;
    }
    return true;
}

function plugin_netstatconnections_check_config(): bool {
    return true;
}
