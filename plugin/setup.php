<?php
/**
 * Plugin: netstatconnections v2.1.0
 * Network Connections — GLPI native inventory enhancement
 *
 * v2.1.0 — Push endpoint architecture (reverted from PRE_INVENTORY hook)
 *   - GLPI 11.0.6 stateless inventory route loads plugins AFTER doHook(PRE_INVENTORY)
 *     fires, so the hook is unreachable. We use a separate /front/push.php
 *     endpoint instead (same approach as v1.x) — agent submits standard
 *     inventory normally, then POSTs connections separately.
 *   - Token-based auth: agent fetches token from agentconfig.php, includes
 *     it as Bearer in push.php POSTs.
 *   - Server-pushed agent config remains: filters configured in GLPI UI
 *     are auto-fetched by agents.
 */

define('PLUGIN_NETSTATCONNECTIONS_VERSION', '2.1.0');
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
    Plugin::registerClass('PluginNetstatconnectionsRelationtype');

    // Config page link
    $PLUGIN_HOOKS['config_page']['netstatconnections'] = 'front/port.php';

    // Register under Setup → Dropdowns
    $PLUGIN_HOOKS['menu_toadd']['netstatconnections'] = ['config' => 'PluginNetstatconnectionsPort'];

    // Setup → Dropdowns
    $PLUGIN_HOOKS['plugin_dropdown_tabs']['netstatconnections'] = [
        'PluginNetstatconnectionsPort'
    ];

    // Firewall bypasses for unauthenticated agent endpoints.
    // GLPI 11 Symfony router intercepts all requests including static files.
    if (class_exists('\Glpi\Http\Firewall')) {
        // vis-network JS/CSS served through PHP passthrough (public MIT library)
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'netstatconnections',
            '#^/front/vis-asset\.php#',
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
        );
        // Agent config endpoint — agents fetch collection settings + push token
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'netstatconnections',
            '#^/front/agentconfig\.php#',
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
        );
        // Push endpoint — agents POST connection data after inventory cycle.
        // Authenticated via Bearer token in Authorization header (validated in push.php).
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'netstatconnections',
            '#^/front/push\.php#',
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
        );
    }

    // Register stateless paths so GLPI doesn't start a session, redirect to
    // login, or perform CSRF/anti-bot checks for these agent endpoints.
    if (class_exists('\Glpi\Http\SessionManager')) {
        if (method_exists('\Glpi\Http\SessionManager', 'registerPluginStatelessPath')) {
            \Glpi\Http\SessionManager::registerPluginStatelessPath(
                '#^/plugins/netstatconnections/front/(agentconfig|push)\.php#'
            );
            \Glpi\Http\SessionManager::registerPluginStatelessPath(
                '#^/marketplace/netstatconnections/front/(agentconfig|push)\.php#'
            );
        }
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
