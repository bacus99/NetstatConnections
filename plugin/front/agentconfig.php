<?php
/**
 * Agent config endpoint — returns collection settings as JSON.
 *
 * Called by agents before each collection cycle:
 *   GET .../front/agentconfig.php
 *   GET .../front/agentconfig.php?hostname=SERVER01
 *
 * No GLPI session required — STRATEGY_NO_CHECK bypass registered in setup.php.
 * The config data is not sensitive (just filter settings).
 */
$glpi_root = realpath(__DIR__ . '/../../..');
if (file_exists($glpi_root . '/vendor/autoload.php')) {
    require_once $glpi_root . '/vendor/autoload.php';
}
include($glpi_root . '/inc/includes.php');

require_once __DIR__ . '/../inc/agentconfig.class.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$config = PluginNetstatconnectionsAgentconfig::get();

// Include the push token so agents can authenticate to push.php
$config['push_token'] = PluginNetstatconnectionsAgentconfig::getToken();

// Future: per-computer or per-entity overrides could be resolved here
// using $_GET['hostname'] or $_GET['deviceid'] to look up the computer
// and return entity-specific settings.

echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
