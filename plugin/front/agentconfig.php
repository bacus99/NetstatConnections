<?php
/**
 * Agent config endpoint — returns collection settings + push token as JSON.
 *
 * Called by agents before each collection cycle:
 *   GET .../front/agentconfig.php
 *   GET .../front/agentconfig.php?hostname=SERVER01
 *
 * STANDALONE — no GLPI bootstrap dependency.
 *
 * GLPI 11.0.7+ does not bootstrap $DB or load plugin classes for
 * plugin-file requests routed through LegacyFileLoadController. To stay
 * reliable across GLPI versions and stateless/stateful flows, this script
 * mirrors push.php's approach: read MySQL credentials from one of the
 * /etc/glpi... config_db.php paths and connect directly via PDO.
 *
 * Auth: none required — the config data is non-sensitive (just collection
 * filters and the push token, which is itself the auth secret for push.php).
 * The Firewall STRATEGY_NO_CHECK + GET method (CSRF-exempt) handle gating.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

function reply(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Locate config_db.php and parse credentials ───────────────────────────
$config_path = null;
foreach ([
    '/etc/glpi11/config_db.php',
    '/etc/glpi/config_db.php',
    __DIR__ . '/../../../config/config_db.php',
] as $p) {
    if (file_exists($p)) { $config_path = $p; break; }
}
if (!$config_path) {
    reply(500, ['error' => 'config_missing']);
}

$contents = file_get_contents($config_path);
$cred = [];
foreach (['dbhost', 'dbuser', 'dbpassword', 'dbdefault'] as $key) {
    if (preg_match('/\$' . $key . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $contents, $m)) {
        $cred[$key] = $m[1];
    }
}
if (!isset($cred['dbhost'], $cred['dbuser'], $cred['dbpassword'], $cred['dbdefault'])) {
    reply(500, ['error' => 'config_parse_failed', 'found' => array_keys($cred)]);
}

// ── Connect via PDO ──────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host={$cred['dbhost']};dbname={$cred['dbdefault']};charset=utf8mb4",
        $cred['dbuser'],
        $cred['dbpassword'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (\Throwable $e) {
    reply(500, ['error' => 'db_connect_failed', 'message' => $e->getMessage()]);
}

// ── Defaults (keep in sync with PluginNetstatconnectionsAgentconfig::DEFAULTS) ───
$defaults = [
    'established_only'         => true,
    'skip_ipv6'                => true,
    'skip_loopback'            => true,
    'ephemeral_port_threshold' => 49152,
    'exclude_processes'        => [],
    'exclude_remote_ips'       => [],
    'exclude_remote_ports'     => [],
    'include_only_ips'         => [],
];

// ── Verify config table exists, read agent_collection settings ───────────
try {
    $check = $pdo->query("SHOW TABLES LIKE 'glpi_plugin_netstatconnections_config'");
    if ($check->rowCount() === 0) {
        // Plugin tables not installed — return defaults only (no token yet)
        reply(200, $defaults + ['push_token' => '']);
    }

    $stmt = $pdo->prepare("SELECT value FROM glpi_plugin_netstatconnections_config WHERE `key` = 'agent_collection' LIMIT 1");
    $stmt->execute();
    $cfg_json = $stmt->fetchColumn();

    $config = $defaults;
    if ($cfg_json) {
        $stored = json_decode($cfg_json, true);
        if (is_array($stored)) {
            $config = array_merge($defaults, $stored);
        }
    }

    // ── Get or generate push token ────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT value FROM glpi_plugin_netstatconnections_config WHERE `key` = 'push_token' LIMIT 1");
    $stmt->execute();
    $token = (string)($stmt->fetchColumn() ?: '');

    if ($token === '') {
        // Auto-generate a new token on first request — matches the behavior of
        // PluginNetstatconnectionsAgentconfig::getToken() in the class file.
        $token = bin2hex(random_bytes(32));
        $ins = $pdo->prepare("INSERT INTO glpi_plugin_netstatconnections_config (`key`, `value`) VALUES ('push_token', ?)");
        $ins->execute([$token]);
    }

    $config['push_token'] = $token;
} catch (\Throwable $e) {
    reply(500, ['error' => 'db_query_failed', 'message' => $e->getMessage()]);
}

// Future: per-computer or per-entity overrides could be resolved here
// using $_GET['hostname'] or $_GET['deviceid'] to look up the computer
// and return entity-specific settings.

reply(200, $config);
