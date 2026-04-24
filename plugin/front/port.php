<?php
include('../../../inc/includes.php');
Session::checkRight('dropdown', READ);
Html::header(
    __('Port Definitions', 'netstatconnections'),
    $_SERVER['PHP_SELF'],
    'config',
    'PluginNetstatconnectionsPort',
    'port'
);

// Force add button — GLPI 11 CommonDropdown menu resolution is unreliable for plugins
echo '<div class="container-fluid mb-3">';
echo '<div class="d-flex justify-content-end">';
echo '<a href="' . Plugin::getWebDir('netstatconnections') . '/front/port.form.php" class="btn btn-primary">';
echo '<i class="ti ti-plus me-1"></i>' . __('Add a port definition') . '</a>';
echo '</div></div>';

Search::show('PluginNetstatconnectionsPort');
Html::footer();
