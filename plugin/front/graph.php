<?php
/**
 * graph.php — Global Network Dependency Map  (Pillar 6)
 *
 * Renders all locked connections as an interactive force-directed graph
 * (vis.js Network).  Nodes = computers / clusters / database instances.
 * Edges = locked service ports, directed by impact_direction, labelled and
 * colour-coded to match the port badge palette.
 *
 * Controls:
 *   • Port filter  — show only one port (MSSQL, HTTPS, …)
 *   • Node search  — highlight / focus a machine by name
 *   • Edge labels  — toggle port labels on/off
 *   • Fit          — re-fit the viewport
 *   • Physics      — re-enable layout engine after drag
 */

require_once __DIR__ . '/../inc/_bootstrap.php';

Session::checkLoginUser();

global $DB;

// ── Focus + embed mode (v2.11.0) ──────────────────────────────────────────────
// No params  → the global locked-topology map (full page, as before).
// for_itemtype/for_items_id → scope the SAME renderer to ONE CI's neighbourhood,
//   so it can be embedded as a "Dependency Graph" tab on a Computer or Appliance.
// embed=1    → drop the GLPI page chrome so it sits cleanly inside an iframe.
$for_type = preg_replace('/[^A-Za-z0-9_\\\\]/', '', (string)($_GET['for_itemtype'] ?? ''));
$for_id   = (int)($_GET['for_items_id'] ?? 0);
$embed    = !empty($_GET['embed']);
$is_focus = ($for_type !== '' && $for_id > 0);

$scope_sql = 'c.is_locked = 1';   // default: confirmed/locked global topology
if ($is_focus && $DB->tableExists('glpi_plugin_netstatconnections_connections')) {
    $anchor_computers = [];   // computer ids whose OUTBOUND edges to include
    $anchor_remote    = [];   // [itemtype, id] this CI appears as a remote target

    if ($for_type === 'Appliance' && class_exists('PluginNetstatconnectionsAppliancedeps')) {
        foreach (PluginNetstatconnectionsAppliancedeps::getMembers($for_id) as $m) {
            if ($m['itemtype'] === 'Computer') $anchor_computers[] = (int)$m['items_id'];
            $anchor_remote[] = [$m['itemtype'], (int)$m['items_id']];
        }
    } elseif ($for_type === 'Computer') {
        $anchor_computers[] = $for_id;
        $anchor_remote[]    = ['Computer', $for_id];
    } else {
        // any other CI type only ever appears as a remote endpoint
        $anchor_remote[] = [$for_type, $for_id];
    }

    $clauses = [];
    if (!empty($anchor_computers)) {
        $clauses[] = 'c.computers_id IN (' . implode(',', array_map('intval', $anchor_computers)) . ')';
    }
    $by_type = [];
    foreach ($anchor_remote as $ar) { $by_type[$ar[0]][] = (int)$ar[1]; }
    foreach ($by_type as $t => $ids) {
        $clauses[] = "(c.remote_itemtype = '" . $DB->escape($t) . "' AND c.remote_items_id IN ("
                   . implode(',', array_map('intval', $ids)) . '))';
    }
    // Focus view = active resolved edges around the CI (locked OR not), so the
    // per-CI graph is actually populated — not just the few locked ones.
    if (!empty($clauses)) {
        $scope_sql = '(' . implode(' OR ', $clauses) . ')';
    }
}

if ($embed) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<style>'
       . 'body{margin:0;font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;font-size:13px;color:#222}'
       . '.btn,.form-select,.form-control{font:inherit;padding:3px 8px;border:1px solid #ccc;border-radius:4px;background:#fff}'
       . '.btn{cursor:pointer}.btn:hover{background:#f1f3f5}'
       . '.vr{display:inline-block;width:1px;height:18px;background:#ddd;margin:0 4px;vertical-align:middle}'
       . '.badge{display:inline-block;padding:2px 6px;border-radius:4px;background:#6c757d;color:#fff;font-size:11px}'
       . '.bg-secondary{background:#6c757d}.text-muted{color:#888}.small{font-size:.85em}'
       . '.form-check{display:inline-flex;align-items:center;gap:4px;margin:0}.form-switch{}'
       . '.me-1{margin-right:4px}.mx-1{margin:0 4px}.ms-auto{margin-left:auto}'
       . '.d-flex{display:flex}.align-items-center{align-items:center}.flex-wrap{flex-wrap:wrap}'
       . '.gap-2{gap:8px}.gap-3{gap:12px}.px-3{padding:0 12px}.py-2{padding:8px 0}.py-1{padding:4px 0}'
       . '.border-bottom{border-bottom:1px solid #e5e5e5}.bg-light{background:#f8f9fa}.bg-white{background:#fff}'
       . '.fw-bold{font-weight:600}.text-primary{color:#0d6efd}.alert{padding:12px;margin:12px}'
       . '.alert-info{background:#e7f1ff;border:1px solid #b6d4fe;border-radius:6px}'
       . '</style></head><body>';
} else {
    Html::header(
        __('Network Dependency Map', 'netstatconnections'),
        $_SERVER['PHP_SELF'],
        'plugins',
        'PluginNetstatconnectionsPort',
        'graph'
    );
}

if (!$DB->tableExists('glpi_plugin_netstatconnections_connections')) {
    echo '<div class="alert alert-warning m-3">Plugin tables missing — please run Update.</div>';
    if ($embed) { echo '</body></html>'; } else { Html::footer(); }
    exit;
}

// ── Query: connections (scoped) with resolved remote CIs ──────────────────────
$sql = "
    SELECT
        c.computers_id,
        c.remote_items_id,
        c.remote_itemtype,
        c.service_port,
        c.protocol,
        c.impact_direction,
        COUNT(*)          AS conn_count,
        MAX(c.seen_count) AS seen_count,
        MAX(p.color)      AS port_color,
        COALESCE(MAX(p.name), CONCAT(c.protocol, ' ', c.service_port)) AS port_name,
        MAX(rt.name)      AS relation_type,
        MAX(rt.color)     AS relation_color
    FROM `glpi_plugin_netstatconnections_connections` c
    LEFT JOIN `glpi_plugin_netstatconnections_ports` p
           ON p.port_number = c.service_port AND p.protocol = c.protocol
    LEFT JOIN `glpi_plugin_netstatconnections_relationtypes` rt
           ON rt.id = p.relation_types_id AND rt.is_deleted = 0
    WHERE {$scope_sql}
      AND c.connection_status = 'active'
      AND c.remote_items_id  > 0
      AND c.remote_itemtype   IS NOT NULL
      AND c.remote_itemtype  != ''
      AND c.remote_items_id  != c.computers_id
    GROUP BY c.computers_id, c.remote_items_id, c.remote_itemtype,
             c.service_port, c.protocol, c.impact_direction
    ORDER BY c.service_port, c.computers_id
";

$result  = $DB->doQuery($sql);
$db_rows = [];
while ($row = $DB->fetchAssoc($result)) {
    $db_rows[] = $row;
}

// ── Build nodes + edges ───────────────────────────────────────────────────────

$node_color = [
    'Computer'         => '#1f77b4',   // steel blue
    'Cluster'          => '#ff7f0e',   // orange
    'DatabaseInstance' => '#d62728',   // red
    'NetworkEquipment' => '#9467bd',   // purple
    'Printer'          => '#2ca02c',   // green
    'Phone'            => '#17a2b8',   // teal
    'Peripheral'       => '#8c564b',   // brown
];
$node_shape = [
    'Computer'         => 'dot',
    'Cluster'          => 'diamond',
    'DatabaseInstance' => 'database',
    'NetworkEquipment' => 'square',
    'Printer'          => 'triangle',
    'Phone'            => 'hexagon',
    'Peripheral'       => 'star',
];

$nodes_raw = [];
$edges_raw = [];
$edge_agg  = [];   // "from|to" => aggregated edge — collapses parallel service
                   //              edges between the same pair into ONE line.
$max_seen  = 1;    // global denominator for the weight %

foreach ($db_rows as $row) {
    $local_key  = 'Computer_'              . (int)$row['computers_id'];
    $remote_key = $row['remote_itemtype'] . '_' . (int)$row['remote_items_id'];

    // ── Local computer node ───────────────────────────────────────────
    if (!isset($nodes_raw[$local_key])) {
        $comp = new Computer();
        if ($comp->getFromDB((int)$row['computers_id'])) {
            $nodes_raw[$local_key] = [
                'id'    => $local_key,
                'label' => $comp->getName(),
                'type'  => 'Computer',
                'url'   => $comp->getLinkURL(),
            ];
        }
    }

    // ── Remote CI node ────────────────────────────────────────────────
    if (!isset($nodes_raw[$remote_key]) && class_exists($row['remote_itemtype'])) {
        $remote = new $row['remote_itemtype']();
        if ($remote->getFromDB((int)$row['remote_items_id'])) {
            $nodes_raw[$remote_key] = [
                'id'    => $remote_key,
                'label' => $remote->getName(),
                'type'  => $row['remote_itemtype'],
                'url'   => $remote->getLinkURL(),
            ];
        }
    }

    // ── Edge (aggregated by node-pair) ─────────────────────────────────
    // impact_direction 'impacts' → Computer is the source (it impacts remote)
    // impact_direction 'depends' → remote is the source (it impacts Computer)
    if (($row['impact_direction'] ?? 'impacts') === 'impacts') {
        $from = $local_key;
        $to   = $remote_key;
    } else {
        $from = $remote_key;
        $to   = $local_key;
    }

    $seen = (int)($row['seen_count'] ?? 1);
    if ($seen > $max_seen) $max_seen = $seen;
    $port = $row['port_name'] ?? ($row['protocol'] . ' ' . $row['service_port']);
    $ak   = $from . '|' . $to;

    if (!isset($edge_agg[$ak])) {
        $edge_agg[$ak] = [
            'from'          => $from,
            'to'            => $to,
            'ports'         => [],
            'seen'          => 0,
            'color'         => $row['port_color'] ?? '#6c757d',
            'relation_type' => $row['relation_type']  ?? '',
            'rel_color'     => $row['relation_color'] ?? '',
        ];
    }
    if ($port !== '' && !in_array($port, $edge_agg[$ak]['ports'], true)) {
        $edge_agg[$ak]['ports'][] = $port;
    }
    $edge_agg[$ak]['seen'] = max($edge_agg[$ak]['seen'], $seen);
    if ($edge_agg[$ak]['relation_type'] === '' && !empty($row['relation_type'])) {
        $edge_agg[$ak]['relation_type'] = $row['relation_type'];
        $edge_agg[$ak]['rel_color']     = $row['relation_color'] ?? '';
    }
}

// Expand aggregated pairs → one render edge each, weighted by observation %.
foreach ($edge_agg as $a) {
    sort($a['ports']);
    $wpct = (int)round(100 * $a['seen'] / max(1, $max_seen));
    $edges_raw[] = [
        'from'          => $a['from'],
        'to'            => $a['to'],
        'label'         => implode(', ', $a['ports']),
        'port'          => $a['ports'][0] ?? '',   // primary, for legacy refs
        'ports'         => $a['ports'],            // full set, for the port filter
        'color'         => $a['color'],
        'weight'        => max(1, min(8, (int)round($a['seen'] / max(1, $max_seen) * 8))),
        'wpct'          => $wpct,
        'seen'          => $a['seen'],
        'relation_type' => $a['relation_type'],
        'rel_color'     => $a['rel_color'],
    ];
}

// ── Port list for filter dropdown ─────────────────────────────────────────────
$port_opts = [];
foreach ($edges_raw as $e) {
    if (!isset($port_opts[$e['port']])) {
        $port_opts[$e['port']] = $e['color'];
    }
}
ksort($port_opts);

// ── Relation type legend (distinct types used in current edge set) ─────────────
$rel_legend = []; // name => color
foreach ($edges_raw as $e) {
    if ($e['relation_type'] !== '' && !isset($rel_legend[$e['relation_type']])) {
        $rel_legend[$e['relation_type']] = $e['rel_color'] ?: '#6c757d';
    }
}
ksort($rel_legend);

// ── Serialise for JS ──────────────────────────────────────────────────────────
$js_nodes    = json_encode(array_values($nodes_raw), JSON_HEX_TAG | JSON_HEX_AMP);
$js_edges    = json_encode($edges_raw,               JSON_HEX_TAG | JSON_HEX_AMP);
$js_colors   = json_encode($node_color);
$js_shapes   = json_encode($node_shape);

$node_count  = count($nodes_raw);
$edge_count  = count($edges_raw);

?>

<!-- full-height layout -->
<div id="netstat-graph-wrap"
     style="display:flex;flex-direction:column;height:calc(100vh - 80px);overflow:hidden">

  <!-- ── Toolbar ─────────────────────────────────────────────────────── -->
  <div class="d-flex align-items-center flex-wrap gap-2 px-3 py-2 bg-light border-bottom"
       style="flex-shrink:0">

    <span class="fw-bold text-primary me-1">
      <i class="ti ti-topology-star-3 me-1"></i>
      <?= __('Network Dependency Map', 'netstatconnections') ?>
    </span>

    <span class="badge bg-secondary" id="ns-node-count"><?= $node_count ?> nodes</span>
    <span class="badge bg-secondary" id="ns-edge-count"><?= $edge_count ?> edges</span>

    <div class="vr mx-1"></div>

    <!-- Port filter -->
    <select class="form-select form-select-sm" style="width:150px" id="ns-port-filter">
      <option value=""><?= __('All ports', 'netstatconnections') ?></option>
      <?php foreach ($port_opts as $pname => $pcolor): ?>
        <option value="<?= htmlspecialchars($pname) ?>">
          <?= htmlspecialchars($pname) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <!-- Min-weight filter — hide low-confidence (rare) edges to cut noise -->
    <span class="vr mx-1"></span>
    <label class="small text-muted mb-0" for="ns-weight" title="<?= __('Hide dependencies seen in fewer than this % of observed cycles', 'netstatconnections') ?>">
      <i class="ti ti-filter"></i> <?= __('Min weight', 'netstatconnections') ?>
    </label>
    <input type="range" id="ns-weight" min="0" max="100" value="0" step="5" style="width:110px">
    <span class="small text-muted" id="ns-weight-val" style="width:34px">0%</span>

    <!-- Node search -->
    <input type="search" id="ns-search"
           class="form-control form-control-sm" style="width:180px"
           placeholder="<?= __('Search host…', 'netstatconnections') ?>">

    <div class="vr mx-1"></div>

    <!-- Toggle labels -->
    <div class="form-check form-switch mb-0 me-1">
      <input class="form-check-input" type="checkbox" id="ns-labels" checked>
      <label class="form-check-label small" for="ns-labels">Labels</label>
    </div>

    <!-- Physics re-enable -->
    <button class="btn btn-sm btn-outline-secondary" id="ns-physics">
      <i class="ti ti-windmill"></i> Layout
    </button>

    <!-- Fit -->
    <button class="btn btn-sm btn-outline-secondary" id="ns-fit">
      <i class="ti ti-arrows-maximize"></i> Fit
    </button>
  </div>

  <?php if (empty($nodes_raw)): ?>
    <div class="alert alert-info m-4">
      <?= $is_focus
            ? __('No resolved dependencies for this item yet. They appear once connections are collected and remote IPs resolve to CIs.', 'netstatconnections')
            : __('No locked connections with resolved CIs yet. Lock some connections first to see the map.', 'netstatconnections') ?>
    </div>
  <?php else: ?>

  <!-- ── Legend ──────────────────────────────────────────────────────── -->
  <div class="d-flex align-items-center flex-wrap gap-3 px-3 py-1 border-bottom bg-white"
       style="flex-shrink:0;font-size:0.78em;color:#555">

    <!-- Node type shapes -->
    <?php foreach ($node_color as $type => $col): ?>
      <span style="display:inline-flex;align-items:center;gap:4px">
        <svg width="12" height="12" style="vertical-align:middle">
          <?php if ($type === 'Cluster'): ?>
            <polygon points="6,0 12,6 6,12 0,6" fill="<?= $col ?>"/>
          <?php elseif ($type === 'DatabaseInstance'): ?>
            <ellipse cx="6" cy="4" rx="5" ry="2.5" fill="<?= $col ?>"/>
            <rect x="1" y="4" width="10" height="5" fill="<?= $col ?>"/>
            <ellipse cx="6" cy="9" rx="5" ry="2.5" fill="<?= $col ?>"/>
          <?php else: ?>
            <circle cx="6" cy="6" r="5.5" fill="<?= $col ?>"/>
          <?php endif; ?>
        </svg>
        <?= $type ?>
      </span>
    <?php endforeach; ?>

    <?php if (!empty($rel_legend)): ?>
      <span class="vr mx-1"></span>
      <!-- Relation type pills (Virima-style) -->
      <?php foreach ($rel_legend as $rname => $rcol): ?>
        <span style="display:inline-flex;align-items:center;gap:5px">
          <span style="display:inline-block;width:28px;height:4px;border-radius:2px;
                background:<?= htmlspecialchars($rcol) ?>"></span>
          <?= htmlspecialchars($rname) ?>
        </span>
      <?php endforeach; ?>
    <?php endif; ?>

    <span class="ms-auto text-muted" style="font-size:0.9em">
      Click node = details &nbsp;·&nbsp; Hover = blast radius
    </span>
  </div>

  <!-- ── Graph area: canvas + side panel ────────────────────────────── -->
  <div id="ns-graph-body" style="flex:1;display:flex;overflow:hidden;min-height:400px">

    <!-- vis.js canvas -->
    <div id="ns-canvas" style="flex:1;background:#f8f9fa;position:relative">
      <div id="ns-loading" style="position:absolute;inset:0;display:flex;align-items:center;
           justify-content:center;flex-direction:column;gap:12px;color:#888;font-size:14px">
        <div class="spinner-border text-secondary" role="status" style="width:2rem;height:2rem"></div>
        <span>Loading graph library…</span>
      </div>
    </div>

    <!-- Side panel — slides in on node click -->
    <div id="ns-panel" style="width:0;flex-shrink:0;overflow:hidden;background:#fff;
         border-left:1px solid #dee2e6;box-shadow:-3px 0 12px rgba(0,0,0,0.07);
         transition:width 0.22s ease;display:flex;flex-direction:column">
      <div style="width:320px;height:100%;display:flex;flex-direction:column;overflow:hidden">

        <!-- Panel header -->
        <div id="ns-panel-head" style="padding:12px 16px;border-bottom:1px solid #eee;
             display:flex;align-items:center;gap:8px;flex-shrink:0">
          <span id="ns-panel-dot" style="width:13px;height:13px;border-radius:50%;
                flex-shrink:0;display:inline-block;border:2px solid rgba(0,0,0,0.15)"></span>
          <span id="ns-panel-title" style="font-weight:600;font-size:13px;flex:1;
                overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
          <button id="ns-panel-close" style="background:none;border:none;cursor:pointer;
                  color:#999;font-size:22px;line-height:1;padding:0 2px"
                  title="Close">&times;</button>
        </div>

        <!-- Panel body (scrollable) -->
        <div id="ns-panel-body" style="flex:1;overflow-y:auto;padding:14px 16px;font-size:13px">
        </div>

        <!-- Panel footer -->
        <div style="padding:12px 16px;border-top:1px solid #eee;flex-shrink:0">
          <a id="ns-panel-link" href="#" target="_blank"
             class="btn btn-sm btn-primary w-100">
            <i class="ti ti-external-link me-1"></i>Open in GLPI
          </a>
        </div>
      </div>
    </div>

  </div><!-- /ns-graph-body -->

  <?php endif; ?>
</div>

<?php
// Serve vis.js locally via vis-asset.php (GLPI 11 routes all requests through Symfony;
// plain .js/.css files cannot be served directly — a PHP passthrough is required).
$vis_local     = __DIR__ . '/lib/vis-network.min.js';
$vis_css_local = __DIR__ . '/lib/vis-network.min.css';
$vis_asset_url = Plugin::getWebDir('netstatconnections', true) . '/front/vis-asset.php';
?>

<?php if (file_exists($vis_css_local)): ?>
<link rel="stylesheet" href="<?= $vis_asset_url ?>?f=vis-network.min.css">
<?php else: ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/vis-network@10.0.2/styles/vis-network.min.css"
      onerror="this.remove()">
<?php endif; ?>

<?php if (file_exists($vis_local)): ?>
<script src="<?= $vis_asset_url ?>?f=vis-network.min.js"></script>
<?php else: ?>
<script src="https://cdn.jsdelivr.net/npm/vis-network@10.0.2/standalone/umd/vis-network.min.js"
        onerror="document.getElementById('ns-loading').innerHTML=
            '<div class=\'alert alert-danger mx-4 text-start\' style=\'max-width:600px\'>'
            +'<h6><i class=\'ti ti-alert-triangle me-1\'></i>vis.js could not load (CDN blocked)</h6>'
            +'<p class=\'mb-2\'>Download the library and place it in the plugin folder to work offline:</p>'
            +'<ol class=\'mb-2\'><li>Download: <a href=\'https://cdn.jsdelivr.net/npm/vis-network@10.0.2/standalone/umd/vis-network.min.js\' target=\'_blank\'>vis-network.min.js</a> '
            +'&amp; <a href=\'https://cdn.jsdelivr.net/npm/vis-network@10.0.2/styles/vis-network.min.css\' target=\'_blank\'>vis-network.min.css</a></li>'
            +'<li>Place both files in <code>plugin/netstatconnections/front/lib/</code> on the GLPI server</li>'
            +'<li>Reload this page</li></ol>'
            +'</div>'"></script>
<?php endif; ?>

<script>
(function () {
    'use strict';

    // Hide loading spinner once we get here (vis.js loaded)
    var loading = document.getElementById('ns-loading');
    if (loading) loading.style.display = 'none';

    // Make graph body fill remaining viewport height
    (function resizeBody() {
        var body = document.getElementById('ns-graph-body');
        if (!body) return;
        var rect = body.getBoundingClientRect();
        body.style.height = Math.max(400, window.innerHeight - rect.top - 4) + 'px';
    })();
    window.addEventListener('resize', function () {
        var body = document.getElementById('ns-graph-body');
        if (!body) return;
        var rect = body.getBoundingClientRect();
        body.style.height = Math.max(400, window.innerHeight - rect.top - 4) + 'px';
    });

    if (typeof vis === 'undefined') return; // CDN failed — error shown via onerror above

    const RAW_NODES  = <?= $js_nodes  ?>;
    const RAW_EDGES  = <?= $js_edges  ?>;
    const NODE_COLOR = <?= $js_colors ?>;
    const NODE_SHAPE = <?= $js_shapes ?>;

    if (!RAW_NODES.length) return;

    // ── Helpers ───────────────────────────────────────────────────────
    // vis-network@10 renders a STRING title as plain text (HTML shows as
    // literal tags); to get formatted tooltips we must hand it a DOM element.
    function htmlTitle(html) {
        const el = document.createElement('div');
        el.innerHTML = html;
        return el;
    }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function makeVisNode(n) {
        const col   = NODE_COLOR[n.type] || '#6c757d';
        const shape = NODE_SHAPE[n.type] || 'dot';
        return {
            id:    n.id,
            label: n.label,
            title: htmlTitle('<b>' + esc(n.type) + '</b><br>' + esc(n.label) + '<br><small>Click to open</small>'),
            url:   n.url,
            shape: shape,
            size:  shape === 'database' ? 14 : 18,
            color: {
                background: col,
                border:     shadeColor(col, -20),
                highlight:  { background: '#ffffff', border: col },
                hover:      { background: shadeColor(col, 40), border: col },
            },
            font: { color: '#212529', size: 11, face: 'Inter,Segoe UI,sans-serif',
                    bold: { color: '#212529' } },
        };
    }

    function makeVisEdge(e, idx, showLabel) {
        // Color by relation type when available, fall back to port badge color
        const edgeCol = e.rel_color || e.color || '#6c757d';
        // Weight-driven styling: persistent edges are thick + solid + opaque;
        // rare edges thin + faint + dashed, so noise recedes by default instead
        // of every line competing equally (the v2.x spaghetti).
        const wpct    = (e.wpct == null) ? 100 : e.wpct;
        const width   = 1 + Math.round((wpct / 100) * 5);     // 1..6 px
        const opacity = 0.25 + (wpct / 100) * 0.65;           // 0.25..0.90
        return {
            id:     idx,
            from:   e.from,
            to:     e.to,
            label:  showLabel ? e.label : '',
            title:  htmlTitle((e.relation_type ? '<b>' + esc(e.relation_type) + '</b><br>' : '') + esc(e.label)
                  + '<br><small>weight ' + wpct + '% &middot; seen ' + (e.seen || 1) + '</small>'),
            port:   e.port,
            arrows: { to: { enabled: true, scaleFactor: 0.55 } },
            color:  { color: edgeCol, highlight: edgeCol, hover: edgeCol, opacity: opacity },
            width:  width,
            dashes: wpct < 15,
            font:   { size: 10, align: 'middle',
                      strokeWidth: 3, strokeColor: '#f8f9fa', color: '#333' },
            smooth: { type: 'dynamic' },
        };
    }

    function shadeColor(hex, pct) {
        hex = hex.replace('#','');
        const num = parseInt(hex, 16);
        const r = Math.min(255, Math.max(0, (num >> 16) + pct));
        const g = Math.min(255, Math.max(0, ((num >> 8) & 0xff) + pct));
        const b = Math.min(255, Math.max(0, (num & 0xff) + pct));
        return '#' + ((1<<24)|(r<<16)|(g<<8)|b).toString(16).slice(1);
    }

    // ── DataSets ──────────────────────────────────────────────────────
    const nodeDS = new vis.DataSet(RAW_NODES.map(makeVisNode));
    const edgeDS = new vis.DataSet(RAW_EDGES.map((e, i) => makeVisEdge(e, i, true)));

    // ── Network options ───────────────────────────────────────────────
    const OPTIONS = {
        physics: {
            enabled: true,
            solver:  'forceAtlas2Based',
            forceAtlas2Based: {
                gravitationalConstant: -80,
                centralGravity:         0.005,
                springLength:          180,
                springConstant:         0.06,
                damping:                0.5,
                avoidOverlap:           0.5,
            },
            stabilization: { iterations: 250, updateInterval: 20 },
        },
        interaction: {
            hover:             true,
            tooltipDelay:      120,
            navigationButtons: false,
            keyboard:          { enabled: true, speed: { x: 10, y: 10, zoom: 0.02 } },
            multiselect:       true,
        },
        layout: { improvedLayout: false },
    };

    const container = document.getElementById('ns-canvas');
    const network   = new vis.Network(
        container,
        { nodes: nodeDS, edges: edgeDS },
        OPTIONS
    );

    // Stop physics after initial layout
    network.on('stabilizationIterationsDone', () => {
        network.setOptions({ physics: { enabled: false } });
        network.fit({ animation: { duration: 600, easingFunction: 'easeInOutQuad' } });
    });

    // ── Side panel ────────────────────────────────────────────────────
    const _panel     = document.getElementById('ns-panel');
    const _panelHead = document.getElementById('ns-panel-head');
    const _panelDot  = document.getElementById('ns-panel-dot');
    const _panelTitle= document.getElementById('ns-panel-title');
    const _panelBody = document.getElementById('ns-panel-body');
    const _panelLink = document.getElementById('ns-panel-link');
    let   _panelOpen = false;

    function _openPanel(nodeId) {
        const node = RAW_NODES.find(n => n.id === nodeId);
        if (!node) return;

        const col        = NODE_COLOR[node.type] || '#6c757d';
        const upstream   = network.getConnectedNodes(nodeId, 'from'); // depend-on
        const downstream = network.getConnectedNodes(nodeId, 'to');   // impacts

        // Header
        _panelDot.style.background = col;
        _panelDot.style.borderColor = shadeColor(col, -30);
        _panelTitle.textContent = node.label;
        _panelLink.href = node.url;

        // Body
        let html = '';

        // Type + blast summary
        html += '<div class="d-flex align-items-center gap-2 mb-3">'
              + '<span class="badge" style="background:' + col + ';color:#fff;font-size:11px">'
              + node.type + '</span>';
        if (upstream.length || downstream.length) {
            html += '<span class="text-muted" style="font-size:12px">'
                  + (upstream.length   ? '<span style="color:#2980b9">▲ ' + upstream.length   + ' upstream</span>'   : '')
                  + (upstream.length && downstream.length ? '&nbsp;&nbsp;' : '')
                  + (downstream.length ? '<span style="color:#c0392b">▼ ' + downstream.length + ' downstream</span>' : '')
                  + '</span>';
        }
        html += '</div>';

        // Connections list
        const connEdges = RAW_EDGES.filter(e => e.from === nodeId || e.to === nodeId);
        if (connEdges.length) {
            html += '<div style="font-size:11px;font-weight:600;color:#999;'
                  + 'letter-spacing:.05em;text-transform:uppercase;margin-bottom:8px">'
                  + 'Connections</div>';
            html += '<div style="display:flex;flex-direction:column;gap:5px">';

            connEdges.forEach(e => {
                const isSource = (e.from === nodeId);
                const peerId   = isSource ? e.to : e.from;
                const peer     = RAW_NODES.find(n => n.id === peerId);
                const peerName = peer ? peer.label : peerId;
                const dirCol   = isSource ? '#c0392b' : '#2980b9';
                const dirArrow = isSource ? '→' : '←';
                const dirTip   = isSource ? 'impacts' : 'depends on';
                const cnt      = e.seen || 1;   // observed cycles, not the scaled weight

                const edgeCol = e.rel_color || e.color || '#6c757d';
                html += '<div style="display:flex;align-items:center;gap:7px;padding:7px 9px;'
                      + 'background:#f8f9fa;border-radius:6px;border:1px solid #eee">'
                      + '<span style="font-size:15px;font-weight:700;color:' + dirCol + ';'
                      + 'flex-shrink:0" title="' + dirTip + '">' + dirArrow + '</span>'
                      + '<span class="badge" style="background:' + edgeCol + ';color:#fff;'
                      + 'font-size:10px;flex-shrink:0;padding:3px 7px">'
                      + (e.relation_type || e.port) + '</span>'
                      + '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;'
                      + 'white-space:nowrap;font-size:12px" title="' + peerName + '">'
                      + peerName + '</span>'
                      + (cnt > 1
                          ? '<span class="badge bg-secondary" style="font-size:10px;'
                            + 'flex-shrink:0">&times;' + cnt + '</span>'
                          : '')
                      + '</div>';
            });

            html += '</div>';
        }

        _panelBody.innerHTML = html;

        // Slide open
        _panel.style.width = '320px';
        _panelOpen = true;
        setTimeout(() => network.redraw(), 230);
    }

    function _closePanel() {
        _panel.style.width = '0';
        _panelOpen = false;
        setTimeout(() => network.redraw(), 230);
    }

    document.getElementById('ns-panel-close').addEventListener('click', _closePanel);

    // Click node → open panel
    network.on('click', params => {
        if (params.nodes.length === 1) {
            _openPanel(params.nodes[0]);
        } else if (!params.nodes.length && _panelOpen) {
            _closePanel();
        }
    });

    // ── Blast-radius highlight ────────────────────────────────────────────
    // hoverNode  → focal node = white; downstream (impacts) = red; upstream (depends-on) = blue;
    //              unrelated nodes + edges fade to grey.
    // blurNode   → restore everything to original colours.

    let _blastNode = null;

    // Floating tooltip — one DOM node, moved with the mouse
    const _blastTip = document.createElement('div');
    _blastTip.style.cssText =
        'position:absolute;pointer-events:none;padding:5px 10px;z-index:20;'
      + 'background:rgba(25,25,25,0.82);color:#fff;border-radius:6px;'
      + 'font-size:12px;line-height:1.7;white-space:nowrap;display:none;';
    container.appendChild(_blastTip);

    function _blastOn(nodeId) {
        if (_blastNode === nodeId) return;
        _blastNode = nodeId;
        container.style.cursor = 'pointer';

        // Directed neighbours via vis edge direction
        const downstream = new Set(network.getConnectedNodes(nodeId, 'to'));   // nodeId → them (impacts)
        const upstream   = new Set(network.getConnectedNodes(nodeId, 'from')); // them → nodeId (depends-on)
        const connEdges  = new Set(network.getConnectedEdges(nodeId));

        // ── Node colours ──────────────────────────────────────────────
        // Only restyle nodes/edges currently in the dataset — never re-add ones
        // hidden by the port/weight filter (vis update() would otherwise upsert).
        const _pN = new Set(nodeDS.getIds());
        const _pE = new Set(edgeDS.getIds());
        nodeDS.update(RAW_NODES.map(n => {
            if (n.id === nodeId) {
                const col = NODE_COLOR[n.type] || '#6c757d';
                return { id: n.id, borderWidth: 3,
                         color: { background: '#ffffff', border: col,
                                  highlight: { background: '#ffffff', border: col } },
                         font:  { color: '#111', size: 13 } };
            }
            if (downstream.has(n.id)) {
                return { id: n.id, borderWidth: 2,
                         color: { background: '#ff7675', border: '#c0392b',
                                  highlight: { background: '#ff7675', border: '#c0392b' } },
                         font:  { color: '#111', size: 11 } };
            }
            if (upstream.has(n.id)) {
                return { id: n.id, borderWidth: 2,
                         color: { background: '#74b9ff', border: '#2980b9',
                                  highlight: { background: '#74b9ff', border: '#2980b9' } },
                         font:  { color: '#111', size: 11 } };
            }
            // Unrelated — fade out
            return { id: n.id, borderWidth: 1,
                     color: { background: '#e8e8e8', border: '#c5c5c5',
                              highlight: { background: '#e8e8e8', border: '#c5c5c5' } },
                     font:  { color: '#bbb', size: 11 } };
        }).filter(u => _pN.has(u.id)));

        // ── Edge colours ──────────────────────────────────────────────
        const showLbl = document.getElementById('ns-labels').checked;
        edgeDS.update(RAW_EDGES.map((e, i) => {
            if (connEdges.has(i)) {
                return { id: i, label: showLbl ? e.label : '',
                         color: { color: e.color, opacity: 1 },
                         width: Math.max(2, Math.round(e.weight * 0.5) + 1) };
            }
            return { id: i, label: '',
                     color: { color: '#d5d5d5', opacity: 0.2 }, width: 1 };
        }).filter(u => _pE.has(u.id)));

        // ── Tooltip ───────────────────────────────────────────────────
        const upTxt   = upstream.size
            ? '<span style="color:#74b9ff;font-weight:600">▲ ' + upstream.size
              + '</span> <span style="color:#aaa">upstream (this depends on)</span>'  : '';
        const downTxt = downstream.size
            ? '<span style="color:#ff7675;font-weight:600">▼ ' + downstream.size
              + '</span> <span style="color:#aaa">downstream (this impacts)</span>'   : '';
        const none    = (!upstream.size && !downstream.size)
            ? '<span style="color:#aaa">No blast radius</span>' : '';

        _blastTip.innerHTML = [upTxt, downTxt, none].filter(Boolean).join('<br>');
        _blastTip.style.display = 'block';
    }

    function _blastOff() {
        if (_blastNode === null) return;
        _blastNode = null;
        container.style.cursor = 'default';
        _blastTip.style.display = 'none';

        const showLbl = document.getElementById('ns-labels').checked;
        const _pN = new Set(nodeDS.getIds());
        const _pE = new Set(edgeDS.getIds());
        nodeDS.update(RAW_NODES.map(makeVisNode).filter(u => _pN.has(u.id)));
        edgeDS.update(RAW_EDGES.map((e, i) => makeVisEdge(e, i, showLbl)).filter(u => _pE.has(u.id)));
    }

    network.on('hoverNode', params => _blastOn(params.node));
    network.on('blurNode',  ()      => _blastOff());

    // Keep tooltip near cursor
    container.addEventListener('mousemove', e => {
        if (_blastNode === null) return;
        const r = container.getBoundingClientRect();
        _blastTip.style.left = (e.clientX - r.left + 16) + 'px';
        _blastTip.style.top  = (e.clientY - r.top  - 12) + 'px';
    });

    // ── Toolbar — Fit ─────────────────────────────────────────────────
    document.getElementById('ns-fit').addEventListener('click', () => {
        network.fit({ animation: { duration: 600, easingFunction: 'easeInOutQuad' } });
    });

    // ── Toolbar — Physics (re-run layout) ─────────────────────────────
    document.getElementById('ns-physics').addEventListener('click', () => {
        network.setOptions({ physics: { enabled: true } });
        setTimeout(() => network.setOptions({ physics: { enabled: false } }), 3000);
    });

    // ── Toolbar — Edge labels toggle ──────────────────────────────────
    document.getElementById('ns-labels').addEventListener('change', function () {
        const show = this.checked;
        const _pE = new Set(edgeDS.getIds());
        edgeDS.update(
            RAW_EDGES.map((e, i) => ({ id: i, label: show ? e.label : '' }))
                     .filter(u => _pE.has(u.id))
        );
    });

    // ── Toolbar — combined Port + Min-weight filter ───────────────────
    // Rebuilds the dataset keeping STABLE edge ids (= index into RAW_EDGES) so
    // the blast-radius highlight, which maps over RAW_EDGES, stays correct after
    // filtering. (The old port-only handler reindexed edges, quietly breaking
    // blast-radius on a filtered view — fixed here.)
    function applyFilters() {
        const sel     = document.getElementById('ns-port-filter').value;
        const minW    = parseInt(document.getElementById('ns-weight').value, 10) || 0;
        const showLbl = document.getElementById('ns-labels').checked;

        // [edge, originalIndex] pairs that pass both filters
        const kept = [];
        RAW_EDGES.forEach((e, i) => {
            const passW = (e.wpct == null) || (e.wpct >= minW);
            const passP = !sel || (Array.isArray(e.ports) ? e.ports.includes(sel) : e.port === sel);
            if (passW && passP) kept.push([e, i]);
        });

        const usedIds = new Set();
        kept.forEach(([e]) => { usedIds.add(e.from); usedIds.add(e.to); });
        const filtNodes = RAW_NODES.filter(n => usedIds.has(n.id));

        nodeDS.clear();
        edgeDS.clear();
        nodeDS.add(filtNodes.map(makeVisNode));
        edgeDS.add(kept.map(([e, i]) => makeVisEdge(e, i, showLbl)));   // id = i (stable)

        document.getElementById('ns-node-count').textContent = filtNodes.length + ' nodes';
        document.getElementById('ns-edge-count').textContent = kept.length + ' edges';

        network.setOptions({ physics: { enabled: true } });
        setTimeout(() => {
            network.setOptions({ physics: { enabled: false } });
            network.fit({ animation: true });
        }, 1200);
    }

    document.getElementById('ns-port-filter').addEventListener('change', applyFilters);
    document.getElementById('ns-weight').addEventListener('input', function () {
        document.getElementById('ns-weight-val').textContent = this.value + '%';
        applyFilters();
    });

    // ── Toolbar — Node search ─────────────────────────────────────────
    let searchTimer;
    document.getElementById('ns-search').addEventListener('input', function () {
        clearTimeout(searchTimer);
        const q = this.value.trim().toLowerCase();
        searchTimer = setTimeout(() => {
            if (!q) { network.unselectAll(); return; }
            const hits = RAW_NODES
                .filter(n => n.label.toLowerCase().includes(q))
                .map(n => n.id);
            network.selectNodes(hits, false);
            if (hits.length) {
                network.focus(hits[0], { scale: 1.4, animation: true });
            }
        }, 300);
    });

})();
</script>

<?php if ($embed) { echo '</body></html>'; } else { Html::footer(); } ?>
