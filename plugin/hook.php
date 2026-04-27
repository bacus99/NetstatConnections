<?php
/**
 * Install / Uninstall hooks for netstatconnections
 * Single source of truth — setup.php must NOT declare these functions.
 */

function plugin_netstatconnections_install(): bool {
    global $DB;

    // ── connections table ──────────────────────────────────────────────
    if (!$DB->tableExists('glpi_plugin_netstatconnections_connections')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_netstatconnections_connections` (
            `id`                INT(11)      NOT NULL AUTO_INCREMENT,
            `computers_id`      INT(11)      NOT NULL DEFAULT 0,
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
            `created_at`        TIMESTAMP    NULL DEFAULT NULL,
            `is_locked`         TINYINT(1)   NOT NULL DEFAULT 0,
            `impact_direction`  ENUM('depends','impacts') NOT NULL DEFAULT 'impacts',
            `remote_items_id`   INT(11)      DEFAULT NULL,
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
            KEY `computers_id`   (`computers_id`),
            KEY `remote_addr`    (`remote_addr`),
            KEY `is_locked`      (`is_locked`),
            KEY `remote_items_id`(`remote_items_id`),
            KEY `collected_at`   (`collected_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // ── Upgrade: add columns if missing ───────────────────────────────
    $conn_cols = [
        'remote_items_id'   => "INT(11) DEFAULT NULL AFTER `impact_direction`",
        'remote_itemtype'   => "VARCHAR(100) DEFAULT NULL AFTER `remote_items_id`",
        'remote_scope'      => "VARCHAR(20) DEFAULT NULL AFTER `remote_itemtype`",
        'resolved_via'      => "ENUM('glpi_ip','dns','unresolved','lock','autolock','db_instance') DEFAULT NULL AFTER `remote_scope`",
        'resolved_at'       => "TIMESTAMP NULL DEFAULT NULL AFTER `resolved_via`",
        'conn_direction'    => "VARCHAR(10) DEFAULT NULL AFTER `resolved_at`",
        'service_port'      => "SMALLINT UNSIGNED DEFAULT NULL AFTER `conn_direction`",
        'collection_method' => "VARCHAR(20) DEFAULT NULL AFTER `service_port`",
        'offload_state'     => "VARCHAR(50) DEFAULT NULL AFTER `collection_method`",
        'applied_setting'   => "VARCHAR(50) DEFAULT NULL AFTER `offload_state`",
        'created_at'        => "TIMESTAMP NULL DEFAULT NULL AFTER `collected_at`",
    ];

    foreach ($conn_cols as $col => $def) {
        if (!$DB->fieldExists('glpi_plugin_netstatconnections_connections', $col)) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_netstatconnections_connections` ADD `{$col}` {$def};");
        }
    }

    // Upgrade resolved_via ENUM if 'lock' and 'autolock' missing
    if ($DB->fieldExists('glpi_plugin_netstatconnections_connections', 'resolved_via')) {
        $res = $DB->doQuery("SHOW COLUMNS FROM `glpi_plugin_netstatconnections_connections` WHERE Field = 'resolved_via'");
        $col_info = $DB->fetchAssoc($res);
        if ($col_info && strpos($col_info['Type'], 'db_instance') === false) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_netstatconnections_connections` MODIFY `resolved_via` ENUM('glpi_ip','dns','unresolved','lock','autolock','sibling','db_instance') DEFAULT NULL;");
        }
    }

    // ── ports table ───────────────────────────────────────────────────
    if (!$DB->tableExists('glpi_plugin_netstatconnections_ports')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_netstatconnections_ports` (
            `id`             INT(11)      NOT NULL AUTO_INCREMENT,
            `name`           VARCHAR(255) NOT NULL DEFAULT '',
            `port_number`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `protocol`       VARCHAR(10)  NOT NULL DEFAULT 'TCP',
            `color`          VARCHAR(10)  NOT NULL DEFAULT '#6c757d',
            `direction`      ENUM('impacts','depends') NOT NULL DEFAULT 'impacts',
            `auto_lock`          TINYINT(1)   NOT NULL DEFAULT 0,
            `auto_direction`     ENUM('impacts','depends') NOT NULL DEFAULT 'impacts',
            `is_database_port`   TINYINT(1)   NOT NULL DEFAULT 0,
            `comment`            TEXT         DEFAULT NULL,
            `is_deleted`     TINYINT(1)   NOT NULL DEFAULT 0,
            `date_creation`  TIMESTAMP    NULL DEFAULT NULL,
            `date_mod`       TIMESTAMP    NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `port_proto` (`port_number`, `protocol`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Seed default port definitions
        $defaults = [
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
            [8403, 'SCOM-GW',   'TCP', '#6c757d'],
            [10123,'SCOM-Web',  'TCP', '#6c757d'],
        ];

        foreach ($defaults as [$port, $name, $proto, $color]) {
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

    // Upgrade: add port columns if missing
    foreach ([
        'auto_lock'        => "TINYINT(1) NOT NULL DEFAULT 0",
        'auto_direction'   => "ENUM('impacts','depends') NOT NULL DEFAULT 'impacts'",
        'is_database_port' => "TINYINT(1) NOT NULL DEFAULT 0",
    ] as $col => $def) {
        if (!$DB->fieldExists('glpi_plugin_netstatconnections_ports', $col)) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_netstatconnections_ports` ADD `{$col}` {$def};");
        }
    }

    // Seed is_database_port = 1 for known DB ports (safe to re-run)
    $DB->doQuery("UPDATE `glpi_plugin_netstatconnections_ports`
        SET `is_database_port` = 1
        WHERE `port_number` IN (1433,1521,3306,5432,5022) AND `protocol` = 'TCP'");

    // v1.3.0 Pillar 2: is_database_port flag
    if (!$DB->fieldExists('glpi_plugin_netstatconnections_ports', 'is_database_port')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_netstatconnections_ports` ADD `is_database_port` TINYINT(1) NOT NULL DEFAULT 0 AFTER `auto_direction`;");
        // Seed known DB ports
        $db_ports = [1433, 5022, 3306, 1521, 5432, 27017];
        $DB->update('glpi_plugin_netstatconnections_ports', [
            'is_database_port' => 1,
        ], ['port_number' => $db_ports]);
    }

    // ── Cron tasks ────────────────────────────────────────────────────
    PluginNetstatconnectionsCrontask::registerCronTasks();


    // v1.2.0 lifecycle migration
    $table = 'glpi_plugin_netstatconnections_connections';
    if ($DB->tableExists($table)) {
        if (!$DB->fieldExists($table, 'last_seen')) {
            $DB->doQuery("ALTER TABLE `{$table}` ADD COLUMN `last_seen` TIMESTAMP NULL DEFAULT NULL AFTER `collected_at`");
        }
        if (!$DB->fieldExists($table, 'connection_status')) {
            $DB->doQuery("ALTER TABLE `{$table}` ADD COLUMN `connection_status` ENUM('active','closed') NOT NULL DEFAULT 'active' AFTER `last_seen`");
        }
        // Backfill
        $DB->doQuery("UPDATE `{$table}` SET `last_seen` = `collected_at`, `connection_status` = 'active' WHERE `last_seen` IS NULL");
        // Indexes (safe to re-run, MySQL ignores if exists)
    }

    // Register cleanup cron
    PluginNetstatconnectionsCrontask::registerCronTasks();

    return true;
}

function plugin_netstatconnections_uninstall(): bool {
    global $DB;

    foreach ([
        'glpi_plugin_netstatconnections_connections',
        'glpi_plugin_netstatconnections_ports',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    PluginNetstatconnectionsCrontask::unregisterCronTasks();

    return true;
}
