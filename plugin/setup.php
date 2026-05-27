<?php
/**
 * Plugin: netstatconnections v2.2.0
 * Network Connections — GLPI native inventory enhancement
 *
 * v2.2.0 — Lifecycle + enrichment + cluster-aware impact routing
 *   - New cron NetstatLifecycle: transitions active→closed for stale rows
 *     (default 72h, ≥3× agent push cycle) and sweeps stale agents (7+ days
 *     silent).
 *   - New cron NetstatEnrich: soft-populates service_port / conn_direction /
 *     impact_direction on unlocked rows from port definitions so the
 *     dependency map fills in without manual locking.
 *   - Cluster-aware impact routing: when a DBI is hosted on a Cluster, the
 *     client edge now goes Source → Cluster → DBI instead of bypassing the
 *     Cluster (matches AlwaysOn / FCI listener topology).
 *   - push.php now bumps last_seen on still-reported locked rows, preventing
 *     them from being closed by the lifecycle cron.
 *   - Agent: fixed URL normalization bug producing /glpiplugins/ instead of
 *     /glpi/plugins/ for agents pointed at marketplace paths.
 *   - Agent: hardened MSSQL.pm against Always On secondary replicas (no more
 *     "uninitialized value" warnings for unavailable databases).
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

define('PLUGIN_NETSTATCONNECTIONS_VERSION', '2.2.2');
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

    // Routing bypasses for unauthenticated agent endpoints.
    //
    // GLPI 11.0.7+ split request gating into TWO independent listeners:
    //
    //   1. FirewallStrategyListener (authentication)  — consults Firewall::STRATEGY_*
    //      AND SessionManager::isResourceStateless()
    //   2. CheckCsrfListener (CSRF protection)        — consults ONLY
    //      SessionManager::isResourceStateless()
    //
    // Before 11.0.7, STRATEGY_NO_CHECK skipped both auth AND CSRF — but in 11.0.7
    // the new CheckCsrfListener no longer consults the Firewall strategy, so POST
    // requests from agents (which have no GLPI session and therefore no CSRF token)
    // get rejected. The endpoints must ALSO be registered as stateless via
    // SessionManager so both listeners short-circuit.
    //
    // Safe for our endpoints because:
    //   - push.php uses raw PDO (no $DB / no GLPI session needed)
    //   - agentconfig.php is a simple GET that bootstraps GLPI normally
    //   - vis-asset.php just serves static JS/CSS via PHP passthrough
    if (class_exists('\Glpi\Http\SessionManager')) {
        // ONLY push.php needs stateless registration. agentconfig.php and
        // vis-asset.php are GET-only and CheckCsrfListener already skips
        // CSRF for bodyless methods (GET / HEAD / OPTIONS / TRACE).
        //
        // Registering them as stateless would prevent GLPI from loading the
        // plugin's autoloader and $DB connection for those requests,
        // breaking PluginNetstatconnectionsAgentconfig::get() with a "class
        // not found" fatal error.
        \Glpi\Http\SessionManager::registerPluginStatelessPath(
            'netstatconnections', '#^/front/push\.php#'
        );
    }

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

    // Historical note: in v2.1.x we removed SessionManager stateless registration
    // because it caused $DB to be null in push.php under GLPI 11.0.6. That side
    // effect no longer matters because push.php now uses raw PDO and bootstraps
    // its own DB connection from /etc/glpi11/config_db.php — fully independent of
    // GLPI's session/$DB state. Registration is now mandatory in 11.0.7+ for CSRF
    // bypass, so we put it back above.
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
