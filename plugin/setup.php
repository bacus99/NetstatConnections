<?php
/**
 * Plugin: netstatconnections v2.0.0
 * Network Connections — GLPI native inventory enhancement
 *
 * v2.0.0 — Native inventory integration
 *   - Server-pushed agent config: agents fetch collection filters from GLPI UI
 *   - PRE_INVENTORY hook replaces custom push endpoint + token auth
 *   - Agent Connections.pm uses standard addEntry() — no custom credentials
 *   - Relation Types with semantic edge coloring in dependency map
 *   - Fixed computers_id / remote_items_id column types to INT UNSIGNED
 */

define('PLUGIN_NETSTATCONNECTIONS_VERSION', '2.0.0');
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

    // DIAGNOSTIC: confirms plugin_init is being called
    error_log('[netstatconnections] plugin_init called for URI=' . ($_SERVER['REQUEST_URI'] ?? 'cli'));

    $PLUGIN_HOOKS['csrf_compliant']['netstatconnections'] = true;

    // Register classes
    Plugin::registerClass('PluginNetstatconnectionsConnection', ['addtabon' => ['Computer']]);
    Plugin::registerClass('PluginNetstatconnectionsPort');
    Plugin::registerClass('PluginNetstatconnectionsRelationtype');

    // Config page link
    $PLUGIN_HOOKS['config_page']['netstatconnections'] = 'front/port.php';

    // Register under Setup → Dropdowns
    $PLUGIN_HOOKS['menu_toadd']['netstatconnections'] = ['config' => 'PluginNetstatconnectionsPort'];

    // Setup → Dropdowns
    $PLUGIN_HOOKS['plugin_dropdown_tabs']['netstatconnections'] = [
        'PluginNetstatconnectionsPort'
    ];

    // PRE_INVENTORY hook — plain function wrapper defined in hook.php
    // Avoids class autoloading issues; hook.php is always included by doHook()
    $PLUGIN_HOOKS['pre_inventory']['netstatconnections']
        = 'plugin_netstatconnections_pre_inventory';

    // Firewall bypasses — STRATEGY_NO_CHECK for endpoints that don't need a session.
    // GLPI 11 Symfony router intercepts all requests including static files.
    if (class_exists('\Glpi\Http\Firewall')) {
        // vis-network JS/CSS served through PHP passthrough (public MIT library)
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'netstatconnections',
            '#^/front/vis-asset\.php#',
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
        );
        // Agent config endpoint — agents fetch collection settings (not sensitive)
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'netstatconnections',
            '#^/front/agentconfig\.php#',
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
        );
    }
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
