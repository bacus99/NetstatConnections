<?php
require_once __DIR__ . '/../inc/_bootstrap.php';
Session::checkRight('dropdown', READ);
Html::header(
    __('Relation Types', 'netstatconnections'),
    $_SERVER['PHP_SELF'],
    'config',
    'PluginNetstatconnectionsRelationtype',
    'relationtype'
);
$port_url = Plugin::getWebDir('netstatconnections', true) . '/front/port.php';
echo '<div class="container-fluid mb-3">';
echo '<div class="d-flex justify-content-between align-items-center">';
echo '<a href="' . htmlspecialchars($port_url) . '" class="btn btn-outline-secondary btn-sm">';
echo '<i class="ti ti-arrow-left me-1"></i>' . __('Port Definitions', 'netstatconnections') . '</a>';
echo '<a href="' . Plugin::getWebDir('netstatconnections') . '/front/relationtype.form.php" class="btn btn-primary btn-sm">';
echo '<i class="ti ti-plus me-1"></i>' . __('Add a relation type', 'netstatconnections') . '</a>';
echo '</div></div>';

Search::show('PluginNetstatconnectionsRelationtype');
Html::footer();
