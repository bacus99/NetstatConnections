<?php
/**
 * Plugin bootstrap helper.
 *
 * Includes GLPI's inc/includes.php so plugin front-end pages have access to
 * Session, $DB, and the rest of GLPI's runtime. Works regardless of whether
 * Apache is serving the request directly (via the public/plugins symlink in
 * GLPI 11) or via Symfony's LegacyFileLoadController.
 *
 * The naive `include('../../../inc/includes.php')` that historically opens
 * GLPI plugin pages fails on GLPI 11 because URLs route through a
 * public/plugins -> ../plugins symlink and the relative `..` traversal
 * doesn't resolve back to the real GLPI root. realpath() canonicalizes the
 * current directory first so the `/../..` math always lands in the right place.
 *
 * Usage from any plugin file (e.g. plugin/front/foo.php):
 *
 *   require_once __DIR__ . '/../inc/_bootstrap.php';
 */

$glpi_root = realpath(__DIR__ . '/../../..');
if ($glpi_root === false || !file_exists($glpi_root . '/inc/includes.php')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error'   => 'glpi_bootstrap_failed',
        'message' => 'Could not locate GLPI root from ' . __DIR__,
    ]);
    exit;
}
require_once $glpi_root . '/inc/includes.php';
