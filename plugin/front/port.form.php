<?php
include('../../../inc/includes.php');

Session::checkRight('dropdown', UPDATE);

$port = new PluginNetstatconnectionsPort();

if (isset($_POST['add'])) {
    $port->add($_POST);
    Html::redirect(Plugin::getWebDir('netstatconnections') . '/front/port.php');
} elseif (isset($_POST['update'])) {
    $port->update($_POST);
    Html::redirect(Plugin::getWebDir('netstatconnections') . '/front/port.php');
} elseif (isset($_POST['purge'])) {
    $port->delete($_POST, 1);
    Html::redirect(Plugin::getWebDir('netstatconnections') . '/front/port.php');
} else {
    Html::header(
        __('Port Definition', 'netstatconnections'),
        $_SERVER['PHP_SELF'],
        'config',
        'PluginNetstatconnectionsPort',
        'port'
    );

    // Back to list link
    echo '<div class="container-fluid mb-2">';
    echo '<a href="' . Plugin::getWebDir('netstatconnections') . '/front/port.php" class="btn btn-outline-secondary btn-sm">';
    echo '<i class="ti ti-arrow-left me-1"></i>' . __('Back to Port Definitions') . '</a>';
    echo '</div>';

    $id = (int)($_GET['id'] ?? -1);
    $port->showForm($id);

    Html::footer();
}
