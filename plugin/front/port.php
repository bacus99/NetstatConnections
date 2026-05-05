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

global $DB;

// ── Agent Push Token section ──────────────────────────────────────────────
$push_token = '';
try {
    $cfg_row = $DB->request([
        'SELECT' => ['value'],
        'FROM'   => 'glpi_plugin_netstatconnections_config',
        'WHERE'  => ['key' => 'push_token'],
        'LIMIT'  => 1,
    ])->current();
    $push_token = $cfg_row['value'] ?? '';
} catch (\Throwable $e) {}

// Regenerate token if requested
if (isset($_POST['regen_token']) && Session::checkRight('dropdown', UPDATE)) {
    $new_token = bin2hex(random_bytes(32));
    if ($push_token) {
        $DB->update('glpi_plugin_netstatconnections_config', ['value' => $new_token], ['key' => 'push_token']);
    } else {
        $DB->insert('glpi_plugin_netstatconnections_config', ['key' => 'push_token', 'value' => $new_token]);
    }
    $push_token = $new_token;
    Html::displayMessageAfterRedirect(__('Push token regenerated', 'netstatconnections'));
}

$push_url = Plugin::getWebDir('netstatconnections', true) . '/front/push.php';

echo '<div class="container-fluid mb-4">';
echo '<div class="card">';
echo '<div class="card-header"><h3 class="card-title">'
    . '<i class="ti ti-cpu me-2"></i>'
    . __('GLPI Agent — NetStat Module Configuration', 'netstatconnections')
    . '</h3></div>';
echo '<div class="card-body">';
echo '<p class="text-muted">'
    . __('The GLPI Agent Perl module replaces the scheduled bat script. It runs inside the GLPI Agent service (no "Run as Administrator" needed).', 'netstatconnections')
    . '</p>';

echo '<div class="row mb-3">';
// Push URL
echo '<div class="col-md-6">';
echo '<label class="form-label fw-bold">' . __('Push URL', 'netstatconnections') . '</label>';
echo '<div class="input-group">';
echo '<input type="text" class="form-control font-monospace" id="push_url_field" readonly value="' . htmlspecialchars($push_url) . '">';
echo '<button class="btn btn-outline-secondary" type="button" onclick="copyField(\'push_url_field\')" title="' . __('Copy') . '"><i class="ti ti-copy"></i></button>';
echo '</div></div>';

// Push Token
echo '<div class="col-md-6">';
echo '<label class="form-label fw-bold">' . __('Agent Push Token', 'netstatconnections') . '</label>';
echo '<div class="input-group">';
echo '<input type="text" class="form-control font-monospace" id="push_token_field" readonly value="' . htmlspecialchars($push_token) . '">';
echo '<button class="btn btn-outline-secondary" type="button" onclick="copyField(\'push_token_field\')" title="' . __('Copy') . '"><i class="ti ti-copy"></i></button>';
if (Session::haveRight('dropdown', UPDATE)) {
    echo '<form method="POST" action="" class="d-inline">';
    echo Html::hidden('regen_token', ['value' => 1]);
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo '<button type="submit" class="btn btn-outline-warning" title="' . __('Generate new token — existing agents must be updated', 'netstatconnections') . '">';
    echo '<i class="ti ti-refresh"></i></button>';
    echo '</form>';
}
echo '</div></div></div>';

// Installation instructions
echo '<div class="alert alert-info mt-3 mb-0">';
echo '<h5><i class="ti ti-info-circle me-1"></i>' . __('Installation steps', 'netstatconnections') . '</h5>';
echo '<ol class="mb-0">';
echo '<li>' . sprintf(__('Copy %s to %s on each managed server.', 'netstatconnections'),
    '<code>plugin/agent/GLPI/Agent/Task/NetStat.pm</code>',
    '<code>C:\\Program Files\\GLPI-Agent\\perl\\agent\\GLPI\\Agent\\Task\\NetStat.pm</code>') . '</li>';
echo '<li>' . sprintf(__('Copy %s to %s', 'netstatconnections'),
    '<code>plugin/agent/GLPI/Agent/Task/NetStat/Version.pm</code>',
    '<code>C:\\Program Files\\GLPI-Agent\\perl\\agent\\GLPI\\Agent\\Task\\NetStat\\Version.pm</code>') . '</li>';
echo '<li>' . sprintf(__('Create %s with the Push URL and Token above.', 'netstatconnections'),
    '<code>C:\\Program Files\\GLPI-Agent\\etc\\conf.d\\netstat.cfg</code>') . '</li>';
echo '<li>' . __('Restart the GLPI Agent service. The NetStat task will run with the next inventory cycle.', 'netstatconnections') . '</li>';
echo '</ol>';
echo '</div>';

echo '</div></div></div>';

// Copy helper
echo '<script>
function copyField(id) {
    var el = document.getElementById(id);
    el.select();
    document.execCommand("copy");
    var btn = el.nextElementSibling;
    btn.innerHTML = "<i class=\"ti ti-check\"></i>";
    setTimeout(function() { btn.innerHTML = "<i class=\"ti ti-copy\"></i>"; }, 1500);
}
</script>';

// ── Add port button ───────────────────────────────────────────────────────
echo '<div class="container-fluid mb-3">';
echo '<div class="d-flex justify-content-end">';
echo '<a href="' . Plugin::getWebDir('netstatconnections') . '/front/port.form.php" class="btn btn-primary">';
echo '<i class="ti ti-plus me-1"></i>' . __('Add a port definition') . '</a>';
echo '</div></div>';

Search::show('PluginNetstatconnectionsPort');
Html::footer();
