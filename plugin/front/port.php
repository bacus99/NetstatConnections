<?php
require_once __DIR__ . '/../inc/_bootstrap.php';
Session::checkRight('dropdown', READ);
Html::header(
    __('Port Definitions', 'netstatconnections'),
    $_SERVER['PHP_SELF'],
    'config',
    'PluginNetstatconnectionsPort',
    'port'
);

// ── Agent module deployment instructions ─────────────────────────────────────
echo '<div class="container-fluid mb-4">';
echo '<div class="card">';
echo '<div class="card-header"><h3 class="card-title">'
    . '<i class="ti ti-cpu me-2"></i>'
    . __('GLPI Agent — Connections Module', 'netstatconnections')
    . '</h3></div>';
echo '<div class="card-body">';
echo '<p class="text-muted">'
    . __('The Perl module runs inside the GLPI Agent service. After each inventory cycle, it fetches collection settings + push token from the GLPI server, then POSTs the connection data to the plugin push endpoint.', 'netstatconnections')
    . '</p>';
echo '<div class="alert alert-info mb-0">';
echo '<h5><i class="ti ti-info-circle me-1"></i>' . __('Installation steps', 'netstatconnections') . '</h5>';
echo '<ol class="mb-0">';
echo '<li>' . sprintf(
    __('Copy %s to %s on each managed server.', 'netstatconnections'),
    '<code>agent/perl/agent/GLPI/Agent/Task/Inventory/Generic/Connections.pm</code>',
    '<code>C:\Program Files\GLPI-Agent\perl\agent\GLPI\Agent\Task\Inventory\Generic\Connections.pm</code>'
) . '</li>';
echo '<li>' . sprintf(
    __('Copy %s to %s on each managed server.', 'netstatconnections'),
    '<code>agent/glpi-netstat-collect.pl</code>',
    '<code>C:\Program Files\GLPI-Agent\glpi-netstat-collect.pl</code>'
) . '</li>';
echo '<li>' . __('Configure collection filters via <strong>Collection Settings</strong> — agents fetch them (and the push token) automatically.', 'netstatconnections') . '</li>';
echo '<li>' . __('Ensure the agent\'s server URL points to the GLPI base (e.g. https://glpi.example.com/glpi/). The push endpoint URL is derived automatically.', 'netstatconnections') . '</li>';
echo '<li>' . __('Restart the GLPI Agent service. Connections will appear after the next inventory cycle.', 'netstatconnections') . '</li>';
echo '</ol>';
echo '</div>';
echo '</div></div></div>';

// ── Action buttons bar ────────────────────────────────────────────────────────
$config_url  = Plugin::getWebDir('netstatconnections', true) . '/front/config.php';
$reltype_url = Plugin::getWebDir('netstatconnections', true) . '/front/relationtype.php';
$graph_url   = Plugin::getWebDir('netstatconnections', true) . '/front/graph.php';

echo '<div class="container-fluid mb-3">';
echo '<div class="d-flex justify-content-between align-items-center">';

// Left: Collection Settings + Relation Types + Dependency Map
echo '<div class="d-flex gap-2">';
echo '<a href="' . htmlspecialchars($config_url) . '" class="btn btn-outline-warning">';
echo '<i class="ti ti-settings me-1"></i>' . __('Collection Settings', 'netstatconnections') . '</a>';
echo '<a href="' . htmlspecialchars($reltype_url) . '" class="btn btn-outline-secondary">';
echo '<i class="ti ti-arrow-fork me-1"></i>' . __('Relation Types', 'netstatconnections') . '</a>';
echo '<a href="' . htmlspecialchars($graph_url) . '" class="btn btn-outline-info" target="_blank">';
echo '<i class="ti ti-topology-star me-1"></i>' . __('Dependency Map', 'netstatconnections') . '</a>';
echo '</div>';

// Right: Add port definition
echo '<a href="' . Plugin::getWebDir('netstatconnections') . '/front/port.form.php" class="btn btn-primary">';
echo '<i class="ti ti-plus me-1"></i>' . __('Add a port definition') . '</a>';

echo '</div></div>';

Search::show('PluginNetstatconnectionsPort');
Html::footer();
