<?php
/**
 * Install / Uninstall hooks for netstatconnections
 * Single source of truth — setup.php must NOT declare these functions.
 */

function plugin_netstatconnections_install(): bool {
    global $DB;

    // ── plugin config table (key-value) ─────────────────────────────────
    if (!$DB->tableExists('glpi_plugin_netstatconnections_config')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_netstatconnections_config` (
            `key`   VARCHAR(100) NOT NULL,
            `value` TEXT         DEFAULT NULL,
            PRIMARY KEY (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // Seed default agent collection config if absent
    $existing_cfg = $DB->request([
        'FROM'  => 'glpi_plugin_netstatconnections_config',
        'WHERE' => ['key' => 'agent_collection'],
        'LIMIT' => 1,
    ])->current();
    if (!$existing_cfg) {
        $DB->insert('glpi_plugin_netstatconnections_config', [
            'key'   => 'agent_collection',
            'value' => json_encode([
                'established_only'         => true,
                'skip_ipv6'                => true,
                'skip_loopback'            => true,
                'ephemeral_port_threshold' => 49152,
                'exclude_processes'        => [],
                'exclude_remote_ips'       => [],
                'exclude_remote_ports'     => [],
                'include_only_ips'         => [],
            ], JSON_PRETTY_PRINT),
        ]);
    }

    // ── connections table ──────────────────────────────────────────────
    if (!$DB->tableExists('glpi_plugin_netstatconnections_connections')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_netstatconnections_connections` (
            `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `computers_id`      INT UNSIGNED NOT NULL DEFAULT 0,
            `protocol`          VARCHAR(10)  NOT NULL DEFAULT '',
            `local_addr`        VARCHAR(50)  NOT NULL DEFAULT '',
            `local_port`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `remote_addr`       VARCHAR(50)  NOT NULL DEFAULT '',
            `remote_port`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `remote_hostname`   VARCHAR(255) DEFAULT NULL,
            `process_name`      VARCHAR(255) NOT NULL DEFAULT '',
            `service_name`      VARCHAR(255) NOT NULL DEFAULT '',
            `state`             VARCHAR(20)  NOT NULL DEFAULT '',
            `collected_at`      TIMESTAMP    NULL DEFAULT NULL,
            `last_seen`         TIMESTAMP    NULL DEFAULT NULL,
            `connection_status` ENUM('active','closed') NOT NULL DEFAULT 'active',
            `created_at`        TIMESTAMP    NULL DEFAULT NULL,
            `is_locked`         TINYINT(1)   NOT NULL DEFAULT 0,
            `impact_direction`  ENUM('depends','impacts') DEFAULT NULL,
            `remote_items_id`   INT UNSIGNED DEFAULT NULL,
            `remote_itemtype`   VARCHAR(100) DEFAULT NULL,
            `remote_scope`      VARCHAR(20)  DEFAULT NULL,
            `resolved_via`      ENUM('glpi_ip','dns','unresolved','lock','autolock','sibling','db_instance') DEFAULT NULL,
            `resolved_at`       TIMESTAMP    NULL DEFAULT NULL,
            `conn_direction`    VARCHAR(10)  DEFAULT NULL,
            `service_port`      SMALLINT UNSIGNED DEFAULT NULL,
            `collection_method` VARCHAR(20)  DEFAULT NULL,
            `offload_state`     VARCHAR(50)  DEFAULT NULL,
            `applied_setting`   VARCHAR(50)  DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `computers_id`      (`computers_id`),
            KEY `remote_addr`       (`remote_addr`),
            KEY `is_locked`         (`is_locked`),
            KEY `remote_items_id`   (`remote_items_id`),
            KEY `collected_at`      (`collected_at`),
            KEY `last_seen`         (`last_seen`),
            KEY `connection_status` (`connection_status`),
            KEY `resolved_via`      (`resolved_via`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // ── Upgrade: add columns if missing ───────────────────────────────
    $conn_cols = [
        'last_seen'         => "TIMESTAMP NULL DEFAULT NULL AFTER `collected_at`",
        'connection_status' => "ENUM('active','closed') NOT NULL DEFAULT 'active' AFTER `last_seen`",
        'created_at'        => "TIMESTAMP NULL DEFAULT NULL AFTER `connection_status`",
        'remote_items_id'   => "INT UNSIGNED DEFAULT NULL AFTER `impact_direction`",
        'remote_itemtype'   => "VARCHAR(100) DEFAULT NULL AFTER `remote_items_id`",
        'remote_scope'      => "VARCHAR(20) DEFAULT NULL AFTER `remote_itemtype`",
        'resolved_via'      => "ENUM('glpi_ip','dns','unresolved','lock','autolock','sibling','db_instance') DEFAULT NULL AFTER `remote_scope`",
        'resolved_at'       => "TIMESTAMP NULL DEFAULT NULL AFTER `resolved_via`",
        'conn_direction'    => "VARCHAR(10) DEFAULT NULL AFTER `resolved_at`",
        'service_port'      => "SMALLINT UNSIGNED DEFAULT NULL AFTER `conn_direction`",
        'collection_method' => "VARCHAR(20) DEFAULT NULL AFTER `service_port`",
        'offload_state'     => "VARCHAR(50) DEFAULT NULL AFTER `collection_method`",
        'applied_setting'   => "VARCHAR(50) DEFAULT NULL AFTER `offload_state`",
    ];

    $table = 'glpi_plugin_netstatconnections_connections';
    foreach ($conn_cols as $col => $def) {
        if (!$DB->fieldExists($table, $col)) {
            $DB->doQuery("ALTER TABLE `{$table}` ADD `{$col}` {$def};");
        }
    }

    // Backfill lifecycle columns
    $DB->doQuery("UPDATE `{$table}` SET `last_seen` = `collected_at`, `connection_status` = 'active' WHERE `last_seen` IS NULL");

    // Upgrade resolved_via ENUM to include all values
    if ($DB->fieldExists($table, 'resolved_via')) {
        $res = $DB->doQuery("SHOW COLUMNS FROM `{$table}` WHERE Field = 'resolved_via'");
        $col_info = $DB->fetchAssoc($res);
        if ($col_info && strpos($col_info['Type'], 'db_instance') === false) {
            $DB->doQuery("ALTER TABLE `{$table}` MODIFY `resolved_via` ENUM('glpi_ip','dns','unresolved','lock','autolock','sibling','db_instance') DEFAULT NULL;");
        }
    }

    // Fix computers_id and remote_items_id to INT UNSIGNED on existing installs
    foreach (['computers_id' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'remote_items_id' => 'INT UNSIGNED DEFAULT NULL'] as $col => $typedef) {
        if ($DB->fieldExists($table, $col)) {
            $res      = $DB->doQuery("SHOW COLUMNS FROM `{$table}` WHERE Field = '{$col}'");
            $col_info = $DB->fetchAssoc($res);
            if ($col_info && stripos($col_info['Type'], 'unsigned') === false) {
                $DB->doQuery("ALTER TABLE `{$table}` MODIFY `{$col}` {$typedef};");
            }
        }
    }

    // Make impact_direction nullable (unlock writes NULL, not '')
    if ($DB->fieldExists($table, 'impact_direction')) {
        $res      = $DB->doQuery("SHOW COLUMNS FROM `{$table}` WHERE Field = 'impact_direction'");
        $col_info = $DB->fetchAssoc($res);
        if ($col_info && $col_info['Null'] === 'NO') {
            $DB->doQuery("ALTER TABLE `{$table}` MODIFY `impact_direction` ENUM('depends','impacts') DEFAULT NULL");
        }
    }

    // Indexes (try/catch — silently skip if already exists)
    foreach ([
        "ALTER TABLE `{$table}` ADD INDEX `last_seen`         (`last_seen`)",
        "ALTER TABLE `{$table}` ADD INDEX `connection_status` (`connection_status`)",
        "ALTER TABLE `{$table}` ADD INDEX `resolved_via`      (`resolved_via`)",
    ] as $idx_sql) {
        try { $DB->doQuery($idx_sql); } catch (\Throwable $e) {}
    }

    // ── relation types table ──────────────────────────────────────────
    if (!$DB->tableExists('glpi_plugin_netstatconnections_relationtypes')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_netstatconnections_relationtypes` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`          VARCHAR(255) NOT NULL DEFAULT '',
            `color`         VARCHAR(10)  NOT NULL DEFAULT '#6c757d',
            `comment`       TEXT         DEFAULT NULL,
            `is_deleted`    TINYINT(1)   NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP    NULL DEFAULT NULL,
            `date_mod`      TIMESTAMP    NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        foreach ([
            ['Communicates',  '#0d6efd'],
            ['Database',      '#dc3545'],
            ['Replicates',    '#e83e8c'],
            ['Authenticates', '#20c997'],
            ['Administers',   '#fd7e14'],
            ['Monitors',      '#6c757d'],
            ['File Share',    '#795548'],
            ['Mail',          '#ffc107'],
        ] as [$rname, $rcolor]) {
            $DB->insert('glpi_plugin_netstatconnections_relationtypes', [
                'name'  => $rname,
                'color' => $rcolor,
            ]);
        }
    }

    // ── ports table ───────────────────────────────────────────────────
    if (!$DB->tableExists('glpi_plugin_netstatconnections_ports')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_netstatconnections_ports` (
            `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`             VARCHAR(255) NOT NULL DEFAULT '',
            `port_number`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `protocol`         VARCHAR(10)  NOT NULL DEFAULT 'TCP',
            `color`            VARCHAR(10)  NOT NULL DEFAULT '#6c757d',
            `direction`        ENUM('impacts','depends') NOT NULL DEFAULT 'impacts',
            `auto_lock`        TINYINT(1)   NOT NULL DEFAULT 0,
            `auto_direction`   ENUM('impacts','depends') NOT NULL DEFAULT 'impacts',
            `is_database_port` TINYINT(1)   NOT NULL DEFAULT 0,
            `relation_types_id` INT UNSIGNED DEFAULT NULL,
            `comment`          TEXT         DEFAULT NULL,
            `is_deleted`       TINYINT(1)   NOT NULL DEFAULT 0,
            `date_creation`    TIMESTAMP    NULL DEFAULT NULL,
            `date_mod`         TIMESTAMP    NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `port_proto` (`port_number`, `protocol`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        foreach ([
            [21,   'FTP',        'TCP', '#17a2b8'],
            [22,   'SSH',        'TCP', '#28a745'],
            [25,   'SMTP',       'TCP', '#ffc107'],
            [53,   'DNS',        'TCP', '#007bff'],
            [53,   'DNS',        'UDP', '#007bff'],
            [80,   'HTTP',       'TCP', '#6f42c1'],
            [110,  'POP3',       'TCP', '#fd7e14'],
            [135,  'RPC',        'TCP', '#6c757d'],
            [143,  'IMAP',       'TCP', '#fd7e14'],
            [389,  'LDAP',       'TCP', '#20c997'],
            [443,  'HTTPS',      'TCP', '#6f42c1'],
            [445,  'SMB',        'TCP', '#795548'],
            [1433, 'MSSQL',      'TCP', '#dc3545'],
            [1521, 'Oracle',     'TCP', '#e83e8c'],
            [3306, 'MySQL',      'TCP', '#007bff'],
            [3389, 'RDP',        'TCP', '#ff5722'],
            [5022, 'AlwaysOn',   'TCP', '#dc3545'],
            [5432, 'PostgreSQL', 'TCP', '#336791'],
            [5723, 'SCOM',       'TCP', '#6c757d'],
            [5985, 'WinRM',      'TCP', '#17a2b8'],
            [8080, 'HTTP-Alt',   'TCP', '#6f42c1'],
            [8403, 'SCOM-GW',    'TCP', '#6c757d'],
            [10123,'SCOM-Web',   'TCP', '#6c757d'],
        ] as [$port, $name, $proto, $color]) {
            $DB->insert('glpi_plugin_netstatconnections_ports', [
                'name'        => $name,
                'port_number' => $port,
                'protocol'    => $proto,
                'color'       => $color,
                'direction'   => 'impacts',
                'auto_lock'   => 0,
            ]);
        }
    }

    // Upgrade ports table: add columns if missing
    foreach ([
        'auto_lock'          => "TINYINT(1) NOT NULL DEFAULT 0",
        'auto_direction'     => "ENUM('impacts','depends') NOT NULL DEFAULT 'impacts'",
        'is_database_port'   => "TINYINT(1) NOT NULL DEFAULT 0",
        'relation_types_id'  => "INT UNSIGNED DEFAULT NULL",
    ] as $col => $def) {
        if (!$DB->fieldExists('glpi_plugin_netstatconnections_ports', $col)) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_netstatconnections_ports` ADD `{$col}` {$def};");
        }
    }

    // Seed is_database_port = 1 for known DB ports (safe to re-run)
    $DB->doQuery("UPDATE `glpi_plugin_netstatconnections_ports`
        SET `is_database_port` = 1
        WHERE `port_number` IN (1433,1521,3306,5432,5022) AND `protocol` = 'TCP'");

    // Auto-assign relation types to seeded ports (only where NULL — safe to re-run)
    if ($DB->tableExists('glpi_plugin_netstatconnections_relationtypes')) {
        $rt_ids = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_netstatconnections_relationtypes']) as $r) {
            $rt_ids[$r['name']] = (int)$r['id'];
        }

        foreach ([
            [1433,  'TCP', 'Database'],
            [1521,  'TCP', 'Database'],
            [3306,  'TCP', 'Database'],
            [5432,  'TCP', 'Database'],
            [5022,  'TCP', 'Replicates'],
            [389,   'TCP', 'Authenticates'],
            [22,    'TCP', 'Administers'],
            [3389,  'TCP', 'Administers'],
            [5985,  'TCP', 'Administers'],
            [5723,  'TCP', 'Monitors'],
            [8403,  'TCP', 'Monitors'],
            [10123, 'TCP', 'Monitors'],
            [445,   'TCP', 'File Share'],
            [21,    'TCP', 'File Share'],
            [25,    'TCP', 'Mail'],
            [110,   'TCP', 'Mail'],
            [143,   'TCP', 'Mail'],
            [80,    'TCP', 'Communicates'],
            [443,   'TCP', 'Communicates'],
            [8080,  'TCP', 'Communicates'],
            [135,   'TCP', 'Communicates'],
            [53,    'TCP', 'Communicates'],
            [53,    'UDP', 'Communicates'],
        ] as [$pnum, $proto, $rtype]) {
            if (!isset($rt_ids[$rtype])) continue;
            $DB->doQuery(
                "UPDATE `glpi_plugin_netstatconnections_ports`
                 SET `relation_types_id` = " . (int)$rt_ids[$rtype] . "
                 WHERE `port_number` = " . (int)$pnum . "
                   AND `protocol`    = '" . $DB->escape($proto) . "'
                   AND (`relation_types_id` IS NULL OR `relation_types_id` = 0)"
            );
        }
    }

    // ── Cron tasks (registered once) ─────────────────────────────────
    PluginNetstatconnectionsCrontask::registerCronTasks();

    return true;
}

/**
 * PRE_INVENTORY hook wrapper — plain function avoids class autoloading issues.
 * GLPI's doHook() calls: call_user_func('plugin_netstatconnections_pre_inventory', $data)
 * $data is a stdClass — object mutations persist (PHP passes objects by handle).
 */
function plugin_netstatconnections_pre_inventory(mixed $data): mixed {
    @file_put_contents('/var/log/glpi/netstatconnections.log',
        '[' . date('Y-m-d H:i:s') . "] hook wrapper called\n", FILE_APPEND | LOCK_EX);
    return PluginNetstatconnectionsInventoryhandler::preInventory($data);
}

function plugin_netstatconnections_uninstall(): bool {
    global $DB;

    foreach ([
        'glpi_plugin_netstatconnections_connections',
        'glpi_plugin_netstatconnections_ports',
        'glpi_plugin_netstatconnections_relationtypes',
        'glpi_plugin_netstatconnections_config',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    PluginNetstatconnectionsCrontask::unregisterCronTasks();

    return true;
}
