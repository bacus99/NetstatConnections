<?php
/**
 * drift.php — Topology Drift review page.
 *
 * Lists dependency changes detected by the NetstatDrift cron: connections that
 * have appeared (a host started talking to a CI it never did) or disappeared
 * (stopped). Admins can acknowledge events to clear them from the default view.
 */
require_once __DIR__ . '/../inc/_bootstrap.php';
Session::checkRight('dropdown', READ);

global $DB;

$can_update = Session::haveRight('dropdown', UPDATE);
$drift      = 'glpi_plugin_netstatconnections_drift';

// ── Handle acknowledge (POST) ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_update && $DB->tableExists($drift)) {
    if (!empty($_POST['ack_ids']) && is_array($_POST['ack_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $_POST['ack_ids'])));
        if ($ids) {
            $DB->update($drift, ['is_acknowledged' => 1], ['id' => $ids]);
            Session::addMessageAfterRedirect(
                sprintf(__('%d drift event(s) acknowledged', 'netstatconnections'), count($ids)), true, INFO
            );
        }
    } elseif (!empty($_POST['ack_all'])) {
        $DB->update($drift, ['is_acknowledged' => 1], ['is_acknowledged' => 0]);
        Session::addMessageAfterRedirect(__('All drift events acknowledged', 'netstatconnections'), true, INFO);
    }
    Html::redirect($_SERVER['PHP_SELF']);
}

Html::header(
    __('Topology Drift', 'netstatconnections'),
    $_SERVER['PHP_SELF'],
    'config',
    'PluginNetstatconnectionsPort',
    'drift'
);

if (!$DB->tableExists($drift)) {
    echo '<div class="alert alert-warning m-3">Drift table missing — please run the plugin Update.</div>';
    Html::footer();
    exit;
}

// ── Filters (GET) ──────────────────────────────────────────────────────────────
$f_type   = $_GET['type']  ?? 'all';                       // all | appeared | disappeared
$f_days   = max(1, min(365, (int)($_GET['days'] ?? 30)));  // window
$f_acked  = !empty($_GET['show_acked']);

// PHP-computed cutoff (plain date arithmetic — no strtotime) keeps the WHERE in
// GLPI's standard ['field' => ['>', 'value']] form.
$cutoff = date('Y-m-d H:i:s', time() - 86400 * $f_days);
$where  = ['detected_at' => ['>', $cutoff]];
if (in_array($f_type, ['appeared', 'disappeared', 'reappeared'], true)) {
    $where['event_type'] = $f_type;
}
if (!$f_acked) {
    $where['is_acknowledged'] = 0;
}

// ── Back / filter bar ────────────────────────────────────────────────────────
$port_url  = Plugin::getWebDir('netstatconnections', true) . '/front/port.php';
$graph_url = Plugin::getWebDir('netstatconnections', true) . '/front/graph.php';

echo '<div class="container-fluid my-3">';
echo '<div class="d-flex justify-content-between align-items-center mb-3">';
echo '<a href="' . htmlspecialchars($port_url) . '" class="btn btn-outline-secondary btn-sm">'
   . '<i class="ti ti-arrow-left me-1"></i>' . __('Port Definitions', 'netstatconnections') . '</a>';
echo '<a href="' . htmlspecialchars($graph_url) . '" class="btn btn-outline-info btn-sm" target="_blank">'
   . '<i class="ti ti-topology-star me-1"></i>' . __('Dependency Map', 'netstatconnections') . '</a>';
echo '</div>';

// Filter form
echo '<form method="GET" class="card card-body mb-3">';
echo '<div class="d-flex flex-wrap align-items-end gap-3">';
echo '<div><label class="form-label mb-1">' . __('Event type', 'netstatconnections') . '</label>';
echo '<select name="type" class="form-select form-select-sm" style="width:160px">';
foreach (['all' => __('All'), 'appeared' => __('Appeared', 'netstatconnections'), 'disappeared' => __('Disappeared', 'netstatconnections'), 'reappeared' => __('Reappeared', 'netstatconnections')] as $v => $lbl) {
    echo '<option value="' . $v . '"' . ($f_type === $v ? ' selected' : '') . '>' . htmlspecialchars($lbl) . '</option>';
}
echo '</select></div>';
echo '<div><label class="form-label mb-1">' . __('Last N days', 'netstatconnections') . '</label>';
echo '<input type="number" name="days" min="1" max="365" value="' . $f_days . '" class="form-control form-control-sm" style="width:100px"></div>';
echo '<div class="form-check mb-1"><input type="checkbox" class="form-check-input" name="show_acked" id="show_acked" value="1"'
   . ($f_acked ? ' checked' : '') . '><label class="form-check-label" for="show_acked">' . __('Include acknowledged', 'netstatconnections') . '</label></div>';
echo '<button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-filter me-1"></i>' . __('Apply') . '</button>';
echo '</div></form>';

// ── Query events ───────────────────────────────────────────────────────────────
$rows = [];
$iter = $DB->request([
    'FROM'  => $drift,
    'WHERE' => $where,
    'ORDER' => ['detected_at DESC'],
    'LIMIT' => 1000,
]);
foreach ($iter as $r) {
    $rows[] = $r;
}

// Resolve computer names once
$comp_ids = array_values(array_unique(array_filter(array_map(fn($r) => (int)$r['computers_id'], $rows))));
$comp_map = [];
if ($comp_ids) {
    foreach ($DB->request([
        'SELECT' => ['id', 'name'],
        'FROM'   => 'glpi_computers',
        'WHERE'  => ['id' => $comp_ids],
    ]) as $c) {
        $comp_map[(int)$c['id']] = $c['name'];
    }
}

// Lazy CI link resolver (cached per itemtype:id)
$ci_cache = [];
$ci_link = function (?string $itemtype, ?int $id, string $fallback) use (&$ci_cache): string {
    $fallback = htmlspecialchars($fallback);
    if (!$itemtype || !$id || !class_exists($itemtype)) return $fallback;
    $key = $itemtype . ':' . $id;
    if (!array_key_exists($key, $ci_cache)) {
        $obj = new $itemtype();
        $ci_cache[$key] = $obj->getFromDB($id)
            ? '<a href="' . $obj->getLinkURL() . '">' . htmlspecialchars($obj->getName()) . '</a>'
            : null;
    }
    return $ci_cache[$key] ?? $fallback;
};

if (empty($rows)) {
    echo '<div class="alert alert-success"><i class="ti ti-check me-1"></i>'
       . __('No drift in the selected window — topology is stable.', 'netstatconnections') . '</div>';
    echo '</div>';
    Html::footer();
    exit;
}

// ── Results table ────────────────────────────────────────────────────────────
echo '<form method="POST">';
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo '<div class="d-flex justify-content-between align-items-center mb-2">';
echo '<span class="text-muted">' . sprintf(__('%d event(s)', 'netstatconnections'), count($rows))
   . (count($rows) >= 1000 ? ' ' . __('(showing latest 1000)', 'netstatconnections') : '') . '</span>';
if ($can_update) {
    echo '<div class="d-flex gap-2">';
    echo '<button type="submit" class="btn btn-outline-secondary btn-sm">'
       . '<i class="ti ti-checks me-1"></i>' . __('Acknowledge selected', 'netstatconnections') . '</button>';
    echo '<button type="submit" name="ack_all" value="1" class="btn btn-outline-warning btn-sm" '
       . 'onclick="return confirm(\'' . __('Acknowledge ALL unacknowledged drift events?', 'netstatconnections') . '\')">'
       . '<i class="ti ti-check-all me-1"></i>' . __('Acknowledge all', 'netstatconnections') . '</button>';
    echo '</div>';
}
echo '</div>';

echo '<div class="table-responsive"><table class="table table-striped table-hover">';
echo '<thead><tr>';
if ($can_update) echo '<th style="width:30px"></th>';
echo '<th>' . __('When', 'netstatconnections') . '</th>';
echo '<th>' . __('Event', 'netstatconnections') . '</th>';
echo '<th>' . __('Source', 'netstatconnections') . '</th>';
echo '<th>' . __('Remote CI', 'netstatconnections') . '</th>';
echo '<th>' . __('Service', 'netstatconnections') . '</th>';
echo '<th>' . __('Process', 'netstatconnections') . '</th>';
echo '</tr></thead><tbody>';

foreach ($rows as $r) {
    switch ($r['event_type']) {
        case 'appeared':
            $badge = '<span class="badge bg-success"><i class="ti ti-plus me-1"></i>'
                   . __('Appeared', 'netstatconnections') . '</span>';
            break;
        case 'reappeared':
            $badge = '<span class="badge bg-info"><i class="ti ti-refresh me-1"></i>'
                   . __('Reappeared', 'netstatconnections') . '</span>';
            break;
        default: // disappeared
            $badge = '<span class="badge bg-danger"><i class="ti ti-minus me-1"></i>'
                   . __('Disappeared', 'netstatconnections') . '</span>';
    }

    $cid       = (int)$r['computers_id'];
    $comp_name = $comp_map[$cid] ?? ('#' . $cid);
    $comp_html = '<a href="' . Computer::getFormURLWithID($cid) . '">' . htmlspecialchars($comp_name) . '</a>';

    $remote_label = $r['remote_hostname'] ?: $r['remote_addr'];
    $remote_html  = $ci_link($r['remote_itemtype'] ?? null, (int)($r['remote_items_id'] ?? 0), (string)$remote_label);
    if (!empty($r['remote_itemtype'])) {
        $remote_html .= ' <small class="text-muted">(' . htmlspecialchars($r['remote_itemtype']) . ')</small>';
    }

    $svc = trim(($r['protocol'] ?? '') . ' ' . (int)($r['service_port'] ?? 0));

    echo '<tr' . ((int)$r['is_acknowledged'] ? ' class="opacity-50"' : '') . '>';
    if ($can_update) {
        echo '<td><input type="checkbox" class="form-check-input" name="ack_ids[]" value="' . (int)$r['id'] . '"></td>';
    }
    echo '<td><small>' . Html::convDateTime($r['detected_at']) . '</small></td>';
    echo '<td>' . $badge . '</td>';
    echo '<td>' . $comp_html . '</td>';
    echo '<td>' . $remote_html . '</td>';
    echo '<td><small class="text-muted">' . htmlspecialchars($svc) . '</small></td>';
    echo '<td><small class="text-muted">' . htmlspecialchars($r['process_name'] ?? '') . '</small></td>';
    echo '</tr>';
}

echo '</tbody></table></div>';
echo '</form>';
echo '</div>';

Html::footer();
