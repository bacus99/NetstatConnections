<?php
/**
 * Plugin: netstatconnections v1.3.2
 * Network Connections — lifecycle push, auto-lock, Impact Analysis
 *
 * v1.3.0 — Pillar 1: Connection lifecycle via bulk push endpoint
 *   - push.php: hardened auth (Session-Token + App-Token validation)
 *   - handleInventory(): $seen_ids init fix, conn_direction in UPDATE/INSERT
 *   - Agent: bulk push mode (single POST replaces delete+insert loop)
 *   - Vanished connections marked 'closed' instead of deleted
 *
 * v1.3.1 — GLPI Agent Perl module + token auth
 *   - New: plugin/agent/GLPI/Agent/Task/NetStat.pm — runs inside GLPI Agent service
 *          (no "Run as Administrator" needed)
 *   - New: plugin config table (push_token) displayed on port.php
 *   - push.php: X-NetStat-Token header auth (Perl module) in addition to Session-Token
 *
 * v1.3.2 — Per-port locking + server-side bulk lock (Pillar 4)
 *   - lock.php: WHERE scoped to (computers_id + remote_addr + service port)
 *   - lock.php: smart unlock — impact removed only when last locked port to remote
 *   - bulk_lock.php: new endpoint — lock/unlock ALL inbound clients on a port
 *   - connection.class.php: inbound group headers + "Lock all inbound" button
 *
 * v1.4.0 — Pillar 7: Cron housekeeping
 *   - cronInfo() — GLPI cron UI now shows task descriptions + param label
 *   - NetstatResolveAll: two-pass resolve, deduped by IP; pass 2 retries
 *     rows previously stamped 'unresolved' (DNS / GLPI inventory may have updated)
 *   - NetstatAutoLock: existing sweep already catches pre-policy connections
 *   - NetstatCleanup: retention days configurable via cron param field (default 30)
 *   - hook.php: indexes on last_seen / connection_status / resolved_via for cron perf
 *   - Impact relation name accumulation fix: _buildImpactName() always reflects
 *     all currently-locked ports; remove-then-ensure bug eliminated
 */

define('PLUGIN_NETSTATCONNECTIONS_VERSION', '1.4.0');
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

    // Allow unauthenticated access to push.php — push.php validates its own token.
    \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
        'netstatconnections',
        '#^/front/push\.php#',
        \Glpi\Http\Firewall::STRATEGY_NO_CHECK
    );

    // Register classes
    Plugin::registerClass('PluginNetstatconnectionsConnection', ['addtabon' => ['Computer']]);
    Plugin::registerClass('PluginNetstatconnectionsPort');

    // Config page link
    $PLUGIN_HOOKS['config_page']['netstatconnections'] = 'front/port.php';

    // Register under Plugins menu
    $PLUGIN_HOOKS['menu_toadd']['netstatconnections'] = ['plugins' => 'PluginNetstatconnectionsPort'];

    // Inventory handler — process netstat data on inventory push
    $PLUGIN_HOOKS['inventory_injection']['netstatconnections'] = [
        'PluginNetstatconnectionsInventoryhandler',
        'handleInventory'
    ];

    // Cron — lets GLPI's cron runner find the plugin's task class
    $PLUGIN_HOOKS['cron']['netstatconnections'] = 'PluginNetstatconnectionsCrontask';
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
