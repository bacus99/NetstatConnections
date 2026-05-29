<?php
require_once __DIR__ . '/../inc/_bootstrap.php';
Session::checkRight('dropdown', UPDATE);

$rt = new PluginNetstatconnectionsRelationtype();

if (isset($_POST['add'])) {
    $rt->check(-1, CREATE, $_POST);
    $rt->add($_POST);
    Html::back();
} elseif (isset($_POST['update'])) {
    $rt->check($_POST['id'], UPDATE, $_POST);
    $rt->update($_POST);
    Html::back();
} elseif (isset($_POST['delete'])) {
    $rt->check($_POST['id'], DELETE, $_POST);
    $rt->delete($_POST);
    $rt->redirectToList();
} elseif (isset($_POST['restore'])) {
    $rt->check($_POST['id'], DELETE, $_POST);
    $rt->restore($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $rt->check($_POST['id'], PURGE, $_POST);
    $rt->delete($_POST, true);
    $rt->redirectToList();
}

Html::header(
    PluginNetstatconnectionsRelationtype::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'config',
    'PluginNetstatconnectionsRelationtype',
    'relationtype'
);
$rt->display(['id' => (int)($_GET['id'] ?? -1)]);
Html::footer();
