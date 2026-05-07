<?php
/**
 * Agent Collection Settings — admin UI page.
 * Allows configuring the global collection filters that agents
 * fetch from the agentconfig.php endpoint.
 */
include('../../../inc/includes.php');
Session::checkRight('config', READ);

Html::header(
    __('Agent Collection Settings', 'netstatconnections'),
    $_SERVER['PHP_SELF'],
    'config',
    'PluginNetstatconnectionsPort',
    'port'
);

$cfg = PluginNetstatconnectionsAgentconfig::get();
$can_update = Session::haveRight('config', UPDATE);

// ── Handle form submission ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_update) {
    Session::checkCSRF($_POST);

    $new_cfg = [
        'established_only'         => isset($_POST['established_only']) ? true : false,
        'skip_ipv6'                => isset($_POST['skip_ipv6']) ? true : false,
        'skip_loopback'            => isset($_POST['skip_loopback']) ? true : false,
        'ephemeral_port_threshold' => max(0, min(65535, (int)($_POST['ephemeral_port_threshold'] ?? 49152))),
        'exclude_processes'        => PluginNetstatconnectionsAgentconfig::textareaToArray($_POST['exclude_processes'] ?? ''),
        'exclude_remote_ips'       => PluginNetstatconnectionsAgentconfig::textareaToArray($_POST['exclude_remote_ips'] ?? ''),
        'exclude_remote_ports'     => array_map('intval', PluginNetstatconnectionsAgentconfig::textareaToArray($_POST['exclude_remote_ports'] ?? '')),
        'include_only_ips'         => PluginNetstatconnectionsAgentconfig::textareaToArray($_POST['include_only_ips'] ?? ''),
    ];
    // Remove zero values from ports
    $new_cfg['exclude_remote_ports'] = array_values(array_filter($new_cfg['exclude_remote_ports']));

    PluginNetstatconnectionsAgentconfig::save($new_cfg);
    $cfg = $new_cfg;
    Session::addMessageAfterRedirect(__('Settings saved', 'netstatconnections'), true, INFO);
}

// ── Back button ──────────────────────────────────────────────────────────────
$port_url = Plugin::getWebDir('netstatconnections', true) . '/front/port.php';
echo '<div class="container-fluid mb-3">';
echo '<a href="' . htmlspecialchars($port_url) . '" class="btn btn-outline-secondary btn-sm">';
echo '<i class="ti ti-arrow-left me-1"></i>' . __('Port Definitions', 'netstatconnections') . '</a>';
echo '</div>';

// ── Settings form ────────────────────────────────────────────────────────────
echo '<div class="container-fluid">';
echo '<form method="POST" action="">';
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

// Card: Collection Filters
echo '<div class="card mb-4">';
echo '<div class="card-header"><h3 class="card-title">'
    . '<i class="ti ti-filter me-2"></i>'
    . __('Collection Filters', 'netstatconnections')
    . '</h3></div>';
echo '<div class="card-body">';

// Row 1: Boolean switches
echo '<div class="row mb-3">';

echo '<div class="col-md-4">';
echo '<div class="form-check form-switch">';
$chk = $cfg['established_only'] ? 'checked' : '';
echo '<input class="form-check-input" type="checkbox" name="established_only" id="established_only" ' . $chk . '>';
echo '<label class="form-check-label" for="established_only">'
    . __('Established only (TCP)', 'netstatconnections')
    . '</label>';
echo '<div class="form-text text-muted">' . __('ESTABLISHED, CLOSE_WAIT, TIME_WAIT only', 'netstatconnections') . '</div>';
echo '</div></div>';

echo '<div class="col-md-4">';
echo '<div class="form-check form-switch">';
$chk = $cfg['skip_ipv6'] ? 'checked' : '';
echo '<input class="form-check-input" type="checkbox" name="skip_ipv6" id="skip_ipv6" ' . $chk . '>';
echo '<label class="form-check-label" for="skip_ipv6">'
    . __('Skip IPv6 connections', 'netstatconnections')
    . '</label>';
echo '</div></div>';

echo '<div class="col-md-4">';
echo '<div class="form-check form-switch">';
$chk = $cfg['skip_loopback'] ? 'checked' : '';
echo '<input class="form-check-input" type="checkbox" name="skip_loopback" id="skip_loopback" ' . $chk . '>';
echo '<label class="form-check-label" for="skip_loopback">'
    . __('Skip loopback connections', 'netstatconnections')
    . '</label>';
echo '</div></div>';

echo '</div>'; // row

// Row 2: Ephemeral threshold
echo '<div class="row mb-3">';
echo '<div class="col-md-4">';
echo '<label class="form-label fw-bold" for="ephemeral_port_threshold">'
    . __('Ephemeral port threshold', 'netstatconnections') . '</label>';
echo '<input type="number" class="form-control" name="ephemeral_port_threshold" '
    . 'id="ephemeral_port_threshold" min="0" max="65535" '
    . 'value="' . (int)$cfg['ephemeral_port_threshold'] . '">';
echo '<div class="form-text text-muted">'
    . __('Remote ports >= this value on outbound connections are skipped. 0 = disabled.', 'netstatconnections')
    . '</div>';
echo '</div></div>';

echo '</div></div>'; // card-body, card

// Card: Exclusion Lists
echo '<div class="card mb-4">';
echo '<div class="card-header"><h3 class="card-title">'
    . '<i class="ti ti-ban me-2"></i>'
    . __('Exclusion Lists', 'netstatconnections')
    . '</h3></div>';
echo '<div class="card-body">';

echo '<div class="row">';

// Exclude processes
echo '<div class="col-md-6 mb-3">';
echo '<label class="form-label fw-bold">'
    . __('Exclude processes', 'netstatconnections') . '</label>';
echo '<textarea class="form-control font-monospace" name="exclude_processes" rows="5" '
    . 'placeholder="svchost.exe&#10;System">'
    . htmlspecialchars(PluginNetstatconnectionsAgentconfig::arrayToTextarea($cfg['exclude_processes']))
    . '</textarea>';
echo '<div class="form-text text-muted">' . __('One process name per line (case-insensitive)', 'netstatconnections') . '</div>';
echo '</div>';

// Exclude remote IPs
echo '<div class="col-md-6 mb-3">';
echo '<label class="form-label fw-bold">'
    . __('Exclude remote IPs', 'netstatconnections') . '</label>';
echo '<textarea class="form-control font-monospace" name="exclude_remote_ips" rows="5" '
    . 'placeholder="10.0.0.1&#10;192.168.1.1">'
    . htmlspecialchars(PluginNetstatconnectionsAgentconfig::arrayToTextarea($cfg['exclude_remote_ips']))
    . '</textarea>';
echo '<div class="form-text text-muted">' . __('One IP address per line', 'netstatconnections') . '</div>';
echo '</div>';

echo '</div><div class="row">';

// Exclude remote ports
echo '<div class="col-md-6 mb-3">';
echo '<label class="form-label fw-bold">'
    . __('Exclude remote ports', 'netstatconnections') . '</label>';
echo '<textarea class="form-control font-monospace" name="exclude_remote_ports" rows="3" '
    . 'placeholder="123&#10;5355">'
    . htmlspecialchars(PluginNetstatconnectionsAgentconfig::arrayToTextarea(array_map('strval', $cfg['exclude_remote_ports'])))
    . '</textarea>';
echo '<div class="form-text text-muted">' . __('One port number per line', 'netstatconnections') . '</div>';
echo '</div>';

// Include only IPs
echo '<div class="col-md-6 mb-3">';
echo '<label class="form-label fw-bold">'
    . __('Include only remote IPs', 'netstatconnections') . '</label>';
echo '<textarea class="form-control font-monospace" name="include_only_ips" rows="3" '
    . 'placeholder="' . __('Empty = collect all', 'netstatconnections') . '">'
    . htmlspecialchars(PluginNetstatconnectionsAgentconfig::arrayToTextarea($cfg['include_only_ips']))
    . '</textarea>';
echo '<div class="form-text text-muted">'
    . __('If set, only connections to these IPs are collected. Empty = all.', 'netstatconnections')
    . '</div>';
echo '</div>';

echo '</div>'; // row
echo '</div></div>'; // card-body, card

// Save button
if ($can_update) {
    echo '<div class="mb-4">';
    echo '<button type="submit" class="btn btn-primary">';
    echo '<i class="ti ti-device-floppy me-1"></i>' . __('Save') . '</button>';
    echo '</div>';
}

echo '</form>';

// ── Info card: Agent endpoint ────────────────────────────────────────────────
$endpoint_url = Plugin::getWebDir('netstatconnections', true) . '/front/agentconfig.php';

echo '<div class="card mb-4">';
echo '<div class="card-header"><h3 class="card-title">'
    . '<i class="ti ti-cloud-download me-2"></i>'
    . __('Agent Config Endpoint', 'netstatconnections')
    . '</h3></div>';
echo '<div class="card-body">';
echo '<p class="text-muted">'
    . __('Agents fetch these settings automatically before each collection cycle. No manual .ini file deployment needed.', 'netstatconnections')
    . '</p>';

echo '<div class="input-group mb-3" style="max-width:600px">';
echo '<input type="text" class="form-control font-monospace" id="endpoint_url" readonly '
    . 'value="' . htmlspecialchars($endpoint_url) . '">';
echo '<button class="btn btn-outline-secondary" type="button" onclick="'
    . 'navigator.clipboard.writeText(document.getElementById(\'endpoint_url\').value);'
    . 'this.innerHTML=\'<i class=\\\'ti ti-check\\\'></i>\';'
    . 'setTimeout(()=>this.innerHTML=\'<i class=\\\'ti ti-copy\\\'></i>\',1500)">'
    . '<i class="ti ti-copy"></i></button>';
echo '</div>';

echo '<div class="alert alert-info mb-0">';
echo '<i class="ti ti-info-circle me-1"></i>'
    . __('The collector script reads this endpoint at the start of each run. '
    . 'If the endpoint is unreachable, the agent falls back to the local netstat-collect.ini file.', 'netstatconnections');
echo '</div>';

echo '</div></div>';
echo '</div>'; // container-fluid

Html::footer();
