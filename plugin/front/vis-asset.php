<?php
/**
 * vis-asset.php — serve local vis-network static files through GLPI's router.
 *
 * GLPI 11 routes all requests via Symfony; plain .js/.css files in plugin
 * directories are never served directly by the web server.  This passthrough
 * lets graph.php load vis-network from a local copy without hitting a CDN.
 *
 * Allowed files are hard-coded (allowlist) — no path traversal possible.
 * No GLPI session required; vis-network is a public MIT library.
 */

// Must be first — no session needed, output headers immediately.
$allowed = [
    'vis-network.min.js'  => 'application/javascript; charset=utf-8',
    'vis-network.min.css' => 'text/css; charset=utf-8',
];

$file = basename((string)($_GET['f'] ?? ''));

if (!isset($allowed[$file])) {
    http_response_code(404);
    exit;
}

$path = __DIR__ . '/lib/' . $file;

if (!file_exists($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: '  . $allowed[$file]);
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
