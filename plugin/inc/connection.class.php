<?php
/**
 * PluginNetstatconnectionsConnection
 * Model + Computer tab display for network connections.
 *
 * v1.1.2 — Unknown ports <49152 shown with grey badges.
 *           Inbound/outbound detection via ephemeral port threshold.
 */
class PluginNetstatconnectionsConnection extends CommonDBTM {
    static $rightname = 'dropdown';

    public static function canCreate(): bool {
        return true;
    }

    public static function canUpdate(): bool {
        return true;
    }

    public static function canDelete(): bool {
        return true;
    }

    public static function canPurge(): bool {
        return true;
    }

    public function canCreateItem(): bool {
        return true;
    }

    public function canUpdateItem(): bool {
        return true;
    }

    public function canDeleteItem(): bool {
        return true;
    }

    public function canPurgeItem(): bool {
        return true;
    }

    public static function canView(): bool {
        return true;
    }

    public static function getIcon() {
        return 'ti ti-network';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item instanceof Computer) {
            global $DB;
            $count = countElementsInTable(
                'glpi_plugin_netstatconnections_connections',
                ['computers_id' => $item->getID()]
            );
            return self::createTabEntry(__('Network Connections', 'netstatconnections'), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item instanceof Computer) {
            self::showForComputer($item);
        }
        return true;
    }

    // ── Inventory handler ────────────────────────────────────────────

    /**
     * Parse various datetime formats into MySQL Y-m-d H:i:s.
     * Handles: "4/15/2026 11:00:05 PM", "2026-04-15T23:00:05", ISO, etc.
     */
    private static function parseDateTime(string $val): string {
        $val = trim($val);
        if ($val === '') {
            return date('Y-m-d H:i:s');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $val)) {
            return $val;
        }
        $ts = strtotime($val);
        if ($ts !== false && $ts > 0) {
            return date('Y-m-d H:i:s', $ts);
        }
        return date('Y-m-d H:i:s');
    }

    /**
     * Merge incoming connections with existing data (v1.3 lifecycle).
     * Match by (computers_id + protocol + remote_addr + remote_port + process_name).
     * - Existing match → UPDATE collected_at, last_seen, state (preserves created_at / age)
     * - New row        → INSERT with connection_status = 'active'
     * - Vanished rows  → UPDATE connection_status = 'closed' (NOT deleted)
     * Locked rows are never touched.
     */
    public static function handleInventory(int $computers_id, array $connections, string $collected_at): void {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_netstatconnections_connections')) return;
        if (empty($connections)) return;

        $seen_ids = [];

        foreach ($connections as $conn) {
            $protocol     = $conn['PROTOCOL']      ?? $conn['protocol']      ?? '';
            $remote_addr  = $conn['REMOTE_ADDR']    ?? $conn['remote_addr']   ?? '';
            $remote_port  = (int)($conn['REMOTE_PORT'] ?? $conn['remote_port'] ?? 0);
            $process_name = $conn['PROCESS_NAME']   ?? $conn['process_name']  ?? '';

            // Match existing row (locked OR unlocked)
            $existing = $DB->request([
                'SELECT' => ['id', 'is_locked'],
                'FROM'   => 'glpi_plugin_netstatconnections_connections',
                'WHERE'  => [
                    'computers_id' => $computers_id,
                    'protocol'     => $protocol,
                    'remote_addr'  => $remote_addr,
                    'remote_port'  => $remote_port,
                    'process_name' => $process_name,
                ],
                'LIMIT' => 1,
            ])->current();

            if ($existing) {
                $seen_ids[] = (int)$existing['id'];

                // Only update unlocked rows — locked rows keep their state
                if ((int)$existing['is_locked'] === 0) {
                    $DB->update('glpi_plugin_netstatconnections_connections', [
                        'collected_at'      => $collected_at,
                        'last_seen'         => date('Y-m-d H:i:s'),
                        'connection_status' => 'active',
                        'state'             => $conn['STATE']          ?? $conn['state']          ?? '',
                        'local_addr'        => $conn['LOCAL_ADDR']     ?? $conn['local_addr']     ?? '',
                        'local_port'        => (int)($conn['LOCAL_PORT'] ?? $conn['local_port']   ?? 0),
                        'remote_hostname'   => $conn['REMOTE_HOSTNAME'] ?? $conn['remote_hostname'] ?? '',
                        'service_name'      => $conn['service_name']   ?? '',
                        'conn_direction'    => $conn['conn_direction']  ?? $conn['CONN_DIRECTION'] ?? 'outbound',
                        'collection_method' => $conn['collection_method'] ?? '',
                    ], ['id' => (int)$existing['id']]);
                } else {
                    // Locked row: only touch last_seen (proves it's still alive)
                    $DB->update('glpi_plugin_netstatconnections_connections', [
                        'last_seen'         => date('Y-m-d H:i:s'),
                        'connection_status' => 'active',
                    ], ['id' => (int)$existing['id']]);
                }
                continue;
            }

            $new_id = $DB->insert('glpi_plugin_netstatconnections_connections', [
                'computers_id'     => $computers_id,
                'protocol'         => $protocol,
                'local_addr'       => $conn['LOCAL_ADDR']     ?? $conn['local_addr']     ?? '',
                'local_port'       => (int)($conn['LOCAL_PORT']   ?? $conn['local_port']  ?? 0),
                'remote_addr'      => $remote_addr,
                'remote_port'      => $remote_port,
                'remote_hostname'  => $conn['REMOTE_HOSTNAME'] ?? $conn['remote_hostname'] ?? '',
                'process_name'     => $conn['PROCESS_NAME']   ?? $conn['process_name']   ?? '',
                'service_name'     => $conn['service_name']                              ?? '',
                'state'            => $conn['STATE']          ?? $conn['state']          ?? '',
                'conn_direction'   => $conn['conn_direction']  ?? $conn['CONN_DIRECTION'] ?? 'outbound',
                'collected_at'     => $collected_at,
                'last_seen'        => date('Y-m-d H:i:s'),
                'connection_status' => 'active',
                'created_at'       => self::parseDateTime($conn['created_at'] ?? ''),
                'collection_method'=> $conn['collection_method'] ?? '',
                'offload_state'    => $conn['offload_state']  ?? '',
                'applied_setting'  => $conn['applied_setting'] ?? '',
                'is_locked'        => 0,
            ]);
            if ($new_id) { $seen_ids[] = (int)$new_id; }
        }


        // Mark unseen unlocked connections as closed
        if (!empty($seen_ids)) {
            $DB->update('glpi_plugin_netstatconnections_connections', [
                'connection_status' => 'closed',
                'last_seen'         => date('Y-m-d H:i:s'),
            ], [
                'computers_id'      => $computers_id,
                'is_locked'         => 0,
                'connection_status' => 'active',
                ['NOT' => ['id' => $seen_ids]],
            ]);
        }
        // Run auto-lock for this computer
        PluginNetstatconnectionsAutolock::processForComputer($computers_id);
    }

    // ── Display ─────────────────────────────────────────────────────

    /** @var bool Self-heal datetime cleanup has run this PHP request */
    private static bool $datetime_scrubbed = false;

    /**
     * Self-heal invalid datetime values across all timestamp columns.
     * Rows inserted by pre-2.2.0 versions of the plugin (or under non-strict
     * MySQL mode) may carry '0000-00-00 00:00:00' or '' in created_at /
     * collected_at / last_seen / resolved_at. Under current strict mode those
     * values trigger "Incorrect datetime value: ''" warnings on every SELECT
     * that aggregates the column (e.g. MAX(created_at) in this tab's UNION
     * query).
     *
     * Uses the `col + 0 = 0` arithmetic trick to identify zero-dates without
     * triggering the strict-mode comparison warning itself. Runs at most once
     * per PHP request via a static flag.
     */
    private static function selfHealDatetimes(): void {
        if (self::$datetime_scrubbed) return;
        self::$datetime_scrubbed = true;

        global $DB;
        $table = 'glpi_plugin_netstatconnections_connections';

        // Temporarily clear strict mode so literal '=' comparisons against
        // zero-dates and '' don't re-fire the very warning we're trying to
        // clean up. Saved/restored so the rest of the request is unaffected.
        $saved_mode = null;
        try {
            $res = $DB->doQuery("SELECT @@SESSION.sql_mode AS m");
            if ($res) {
                $row = $DB->fetchAssoc($res);
                $saved_mode = $row['m'] ?? null;
            }
            $DB->doQuery("SET SESSION sql_mode = ''");
        } catch (\Throwable $e) { /* fall through — best effort */ }

        foreach (['created_at', 'collected_at', 'last_seen', 'resolved_at'] as $col) {
            if (!$DB->fieldExists($table, $col)) continue;
            try {
                $DB->doQuery(
                    "UPDATE `{$table}` SET `{$col}` = NULL "
                    . "WHERE `{$col}` = '0000-00-00 00:00:00' OR `{$col}` = ''"
                );
            } catch (\Throwable $e) { /* ignore — never block the page render */ }
            // Belt-and-suspenders: arithmetic catch for anything the literal
            // compare missed (different zero forms, partial dates, etc.)
            try {
                $DB->doQuery(
                    "UPDATE `{$table}` SET `{$col}` = NULL "
                    . "WHERE `{$col}` IS NOT NULL AND (`{$col}` + 0) = 0"
                );
            } catch (\Throwable $e) { /* ignore */ }
        }

        // Restore previous sql_mode
        if ($saved_mode !== null) {
            try {
                $DB->doQuery("SET SESSION sql_mode = " . $DB->quote($saved_mode));
            } catch (\Throwable $e) { /* ignore */ }
        }
    }

    /**
     * Build a pid → {user, cmd, memusage, started} map from GLPI's native
     * process inventory (glpi_items_processes) for a given computer.
     *
     * This is the correlation source for the "Running as" enrichment — we read
     * the user/command GLPI already collected rather than duplicating it. The
     * table is a current-snapshot (replaced each inventory), so this enriches
     * active edges against the latest process list; closed/historical edges
     * simply won't correlate, which is fine.
     *
     * If the same PID appears more than once (rare; stale dynamic rows), the
     * most recently started one wins.
     *
     * @return array<int, array{user:string, cmd:string, memusage:?float, started:?string}>
     */
    private static function buildProcessMap(int $computers_id): array {
        global $DB;
        if ($computers_id <= 0 || !$DB->tableExists('glpi_items_processes')) {
            return [];
        }

        $map = [];
        try {
            $iter = $DB->request([
                'SELECT' => ['pid', 'user', 'cmd', 'memusage', 'started'],
                'FROM'   => 'glpi_items_processes',
                'WHERE'  => [
                    'itemtype'   => 'Computer',
                    'items_id'   => $computers_id,
                    'is_deleted' => 0,
                ],
            ]);
            foreach ($iter as $p) {
                $pid = (int)($p['pid'] ?? 0);
                if ($pid <= 0) continue;
                // Keep the most recently started row for a reused PID.
                if (isset($map[$pid])
                    && ($map[$pid]['started'] ?? '') >= ($p['started'] ?? '')) {
                    continue;
                }
                $map[$pid] = [
                    'user'     => (string)($p['user'] ?? ''),
                    'cmd'      => (string)($p['cmd']  ?? ''),
                    'memusage' => isset($p['memusage']) ? (float)$p['memusage'] : null,
                    'started'  => $p['started'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $map;
    }

    public static function showForComputer(Computer $computer): void {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_netstatconnections_connections')) {
            echo '<div class="alert alert-warning m-3">Plugin table missing — please run Update.</div>';
            return;
        }

        // One-time-per-request scrub of invalid datetime values so the MAX()
        // aggregates below don't emit "Incorrect datetime value" warnings.
        self::selfHealDatetimes();

        $computers_id = (int) $computer->getID();

        // Last collection timestamp
        $last = $DB->request([
            // Defensive MAX: arithmetic coercion filters out '0000-00-00 00:00:00'
            // and '' so strict mode doesn't emit "Incorrect datetime value" warnings.
            'SELECT' => new \Glpi\DBAL\QueryExpression(
                'MAX(IF(IFNULL(`collected_at` + 0, 0) > 0, `collected_at`, NULL)) AS `last_collected`'
            ),
            'FROM'   => 'glpi_plugin_netstatconnections_connections',
            'WHERE'  => ['computers_id' => $computers_id],
        ])->current();
        $last_ts = $last['last_collected'] ?? null;

        // Get known port numbers for IN clause
        $known_ports = PluginNetstatconnectionsPort::getKnownPortNumbers();
        $ephemeral   = 49152;

        // Database ports (v2.8.2): inbound groups on these ports name the LOCAL
        // DatabaseInstance in their header — the instance is the local side of
        // an inbound DB connection, which the remote column can never show.
        $db_ports = [];
        foreach ($DB->request([
            'SELECT' => ['port_number', 'protocol'],
            'FROM'   => 'glpi_plugin_netstatconnections_ports',
            'WHERE'  => ['is_database_port' => 1],
        ]) as $p) {
            $db_ports[(int)$p['port_number'] . '_' . strtoupper($p['protocol'])] = true;
        }
        $local_inst_cache = [];   // svcPort => resolved local instance row|null

        // ── Build grouped query ──────────────────────────────────────
        // We use raw SQL with UNION to handle three cases:
        //   1. Outbound to known ports  (remote_port IN known)
        //   2. Inbound on known ports   (local_port IN known, remote_port NOT IN known)
        //   3. Unknown outbound         (neither port known, remote_port < ephemeral)

        $table = 'glpi_plugin_netstatconnections_connections';

        if (!empty($known_ports)) {
            $ports_in = implode(',', array_map('intval', $known_ports));

            $sql = "
                -- Outbound: remote_port is a known service
                SELECT
                    MAX(id) AS id,
                    computers_id,
                    protocol,
                    remote_addr,
                    remote_port AS service_port,
                    MAX(remote_hostname) AS remote_hostname,
                    MAX(process_name) AS process_name,
                    MAX(service_name) AS service_name,
                    MAX(is_locked) AS is_locked,
                    MAX(impact_direction) AS impact_direction,
                    MAX(remote_items_id) AS remote_items_id,
                    MAX(remote_itemtype) AS remote_itemtype,
                    MAX(remote_scope) AS remote_scope,
                    -- Safe to MAX once the column is clean: hook.php migration drops
                    -- and re-adds with explicit_defaults_for_timestamp=1 so existing
                    -- rows are NULL and new pushes write valid TIMESTAMP values.
                    MAX(created_at) AS created_at,
                    MAX(process_pid) AS process_pid,
                    MAX(session_id)  AS session_id,
                    MAX(seen_count)  AS seen_count,
                    MIN(first_seen)  AS first_seen,
                    MAX(conn_count) AS conn_count,
                    'out' AS direction
                FROM `{$table}`
                WHERE computers_id = {$computers_id}
                  AND remote_port IN ({$ports_in})
                  AND connection_status = 'active'
                GROUP BY `{$table}`.protocol, `{$table}`.remote_port, `{$table}`.remote_addr, `{$table}`.process_name

                UNION ALL

                -- Inbound: local_port is a known service, remote_port is ephemeral
                SELECT
                    MAX(id) AS id,
                    computers_id,
                    protocol,
                    remote_addr,
                    local_port AS service_port,
                    MAX(remote_hostname) AS remote_hostname,
                    MAX(process_name) AS process_name,
                    MAX(service_name) AS service_name,
                    MAX(is_locked) AS is_locked,
                    MAX(impact_direction) AS impact_direction,
                    MAX(remote_items_id) AS remote_items_id,
                    MAX(remote_itemtype) AS remote_itemtype,
                    MAX(remote_scope) AS remote_scope,
                    -- Safe to MAX once the column is clean: hook.php migration drops
                    -- and re-adds with explicit_defaults_for_timestamp=1 so existing
                    -- rows are NULL and new pushes write valid TIMESTAMP values.
                    MAX(created_at) AS created_at,
                    MAX(process_pid) AS process_pid,
                    MAX(session_id)  AS session_id,
                    MAX(seen_count)  AS seen_count,
                    MIN(first_seen)  AS first_seen,
                    MAX(conn_count) AS conn_count,
                    'in' AS direction
                FROM `{$table}`
                WHERE computers_id = {$computers_id}
                  AND local_port IN ({$ports_in})
                  AND remote_port NOT IN ({$ports_in})
                  AND connection_status = 'active'
                GROUP BY `{$table}`.protocol, `{$table}`.local_port, `{$table}`.remote_addr, `{$table}`.process_name

                UNION ALL

                -- Unknown outbound: neither port known, remote_port < ephemeral
                SELECT
                    MAX(id) AS id,
                    computers_id,
                    protocol,
                    remote_addr,
                    remote_port AS service_port,
                    MAX(remote_hostname) AS remote_hostname,
                    MAX(process_name) AS process_name,
                    MAX(service_name) AS service_name,
                    MAX(is_locked) AS is_locked,
                    MAX(impact_direction) AS impact_direction,
                    MAX(remote_items_id) AS remote_items_id,
                    MAX(remote_itemtype) AS remote_itemtype,
                    MAX(remote_scope) AS remote_scope,
                    -- Safe to MAX once the column is clean: hook.php migration drops
                    -- and re-adds with explicit_defaults_for_timestamp=1 so existing
                    -- rows are NULL and new pushes write valid TIMESTAMP values.
                    MAX(created_at) AS created_at,
                    MAX(process_pid) AS process_pid,
                    MAX(session_id)  AS session_id,
                    MAX(seen_count)  AS seen_count,
                    MIN(first_seen)  AS first_seen,
                    MAX(conn_count) AS conn_count,
                    'out' AS direction
                FROM `{$table}`
                WHERE computers_id = {$computers_id}
                  AND remote_port NOT IN ({$ports_in})
                  AND local_port NOT IN ({$ports_in})
                  AND remote_port < {$ephemeral}
                  AND remote_port > 0
                  AND connection_status = 'active'
                GROUP BY `{$table}`.protocol, `{$table}`.remote_port, `{$table}`.remote_addr, `{$table}`.process_name

                ORDER BY is_locked DESC, service_port ASC, remote_addr ASC
            ";
        } else {
            // No port definitions — show everything with remote_port < ephemeral
            $sql = "
                SELECT
                    MAX(id) AS id,
                    computers_id,
                    protocol,
                    remote_addr,
                    CASE
                        WHEN remote_port < {$ephemeral} THEN remote_port
                        WHEN local_port < {$ephemeral} THEN local_port
                        ELSE remote_port
                    END AS service_port,
                    MAX(remote_hostname) AS remote_hostname,
                    MAX(process_name) AS process_name,
                    MAX(service_name) AS service_name,
                    MAX(is_locked) AS is_locked,
                    MAX(impact_direction) AS impact_direction,
                    MAX(remote_items_id) AS remote_items_id,
                    MAX(remote_itemtype) AS remote_itemtype,
                    MAX(remote_scope) AS remote_scope,
                    -- Safe to MAX once the column is clean: hook.php migration drops
                    -- and re-adds with explicit_defaults_for_timestamp=1 so existing
                    -- rows are NULL and new pushes write valid TIMESTAMP values.
                    MAX(created_at) AS created_at,
                    MAX(process_pid) AS process_pid,
                    MAX(session_id)  AS session_id,
                    MAX(seen_count)  AS seen_count,
                    MIN(first_seen)  AS first_seen,
                    MAX(conn_count) AS conn_count,
                    CASE
                        WHEN remote_port >= {$ephemeral} THEN 'in'
                        ELSE 'out'
                    END AS direction
                FROM `{$table}`
                WHERE computers_id = {$computers_id}
                  AND remote_addr != ''
                  AND remote_addr != '0.0.0.0'
                  AND remote_addr != '127.0.0.1'
                  AND connection_status = 'active'
                GROUP BY `{$table}`.protocol,
                    CASE WHEN `{$table}`.remote_port < {$ephemeral} THEN `{$table}`.remote_port WHEN `{$table}`.local_port < {$ephemeral} THEN `{$table}`.local_port ELSE `{$table}`.remote_port END,
                    `{$table}`.remote_addr, `{$table}`.process_name
                ORDER BY is_locked DESC, service_port ASC, remote_addr ASC
            ";
        }

        $result = $DB->doQuery($sql);
        $rows = [];
        while ($row = $DB->fetchAssoc($result)) {
            $rows[] = $row;
        }

        // ── Process correlation (v2.3.0) ─────────────────────────────
        // Enrich each edge with the owning process's user/command, pulled from
        // GLPI's native process inventory (glpi_items_processes, populated by the
        // agent). We store only the PID on our side; user/cmd live in GLPI core,
        // so no command-line text (or embedded secrets) is duplicated into our
        // table. Correlation key: (computers_id, pid), preferring the row whose
        // `started` is closest to — and not after — the connection's
        // process_started when we have it (reuse-proof). Degrades to PID-only.
        $proc_map = self::buildProcessMap($computers_id);
        if (!empty($proc_map)) {
            foreach ($rows as &$r) {
                $pid = (int)($r['process_pid'] ?? 0);
                if ($pid > 0 && isset($proc_map[$pid])) {
                    $p = $proc_map[$pid];
                    $r['proc_user'] = $p['user'] ?? '';
                    $r['proc_cmd']  = $p['cmd']  ?? '';
                    $r['proc_mem']  = $p['memusage'] ?? null;
                }
            }
            unset($r);
        }

        // ── Render HTML ──────────────────────────────────────────────

        $lock_url      = Plugin::getWebDir('netstatconnections') . '/front/lock.php';
        $bulk_lock_url = Plugin::getWebDir('netstatconnections') . '/front/bulk_lock.php';

        // ── Pre-compute inbound groups for bulk-lock headers ─────────
        // Key: direction|protocol|service_port  → total / locked counts
        // Also derive $cycles_observed = the host's most-observed edge's
        // seen_count, used as the denominator for the true weight ratio
        // (v2.9.1). An always-on dependency (DC / monitoring / DNS) is seen
        // every push, so its seen_count ≈ the number of collection cycles this
        // host has gone through — i.e. "seen 167 / 168 cycles".
        $group_meta = [];
        $cycles_observed = 1;
        foreach ($rows as $r) {
            $gk = ($r['direction'] ?? 'out') . '|' . ($r['protocol'] ?? 'TCP') . '|' . (int)($r['service_port'] ?? 0);
            if (!isset($group_meta[$gk])) {
                $group_meta[$gk] = ['total' => 0, 'locked' => 0,
                                    'direction'    => $r['direction']    ?? 'out',
                                    'protocol'     => $r['protocol']     ?? 'TCP',
                                    'service_port' => (int)($r['service_port'] ?? 0)];
            }
            $group_meta[$gk]['total']++;
            if ((int)$r['is_locked']) $group_meta[$gk]['locked']++;
            $cycles_observed = max($cycles_observed, (int)($r['seen_count'] ?? 1));
        }

        // Count closed connections for badge
        $closed_count = countElementsInTable(
            'glpi_plugin_netstatconnections_connections',
            ['computers_id' => $computers_id, 'connection_status' => 'closed']
        );

        echo '<div class="card m-3">';
        echo '<div class="card-header d-flex justify-content-between align-items-center">';
        echo '<h3 class="card-title mb-0">' . __('Network Connections', 'netstatconnections') . '</h3>';
        echo '<div class="d-flex align-items-center gap-2">';
        if ($closed_count > 0) {
            echo '<span class="badge bg-secondary" title="Connections no longer seen — will be purged by cron">'
               . $closed_count . ' closed</span>';
        }
        if ($last_ts) {
            echo '<span class="text-muted" style="font-size:0.85em">Last collected: ' . Html::convDateTime($last_ts) . '</span>';
        }
        echo '</div>';
        echo '</div>';

        if (empty($rows)) {
            echo '<div class="card-body"><p class="text-muted">No connections collected yet.</p></div>';
            echo '</div>';
            return;
        }

        echo '<div class="table-responsive">';
        echo '<table class="table table-striped table-hover mb-0">';
        echo '<thead><tr>';
        echo '<th style="width:40px">Lock</th>';
        echo '<th style="width:40px">Dir</th>';
        echo '<th>Port</th>';
        echo '<th>Remote</th>';
        echo '<th>Process</th>';
        echo '<th>Age</th>';
        echo '<th style="width:90px" title="How consistently this dependency is observed — the long-tail signal agentless scans miss">Weight</th>';
        echo '<th style="width:30px">#</th>';
        echo '<th style="width:50px">Impact</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $prev_gkey = null;

        foreach ($rows as $conn) {
            $id        = (int)$conn['id'];
            $locked    = (int)$conn['is_locked'];
            $dir       = $conn['direction'] ?? 'out';
            $svcPort   = (int)($conn['service_port'] ?? 0);
            $proto     = $conn['protocol'] ?? 'TCP';
            $direction = $locked ? ($conn['impact_direction'] ?? 'impacts') : 'impacts';

            // ── Inbound group header (injected once per unique inbound port) ──
            $gkey = $dir . '|' . $proto . '|' . $svcPort;
            if ($dir === 'in' && $gkey !== $prev_gkey) {
                $prev_gkey = $gkey;
                $g = $group_meta[$gkey] ?? ['total' => 1, 'locked' => 0];
                if ($g['total'] >= 2) {
                    $all_locked  = ($g['locked'] === $g['total']);
                    $some_locked = ($g['locked'] > 0);
                    $btn_locked  = $all_locked ? 0 : 1;
                    $btn_label   = $all_locked
                        ? '<i class="ti ti-lock-open me-1"></i>Unlock all inbound'
                        : '<i class="ti ti-lock me-1"></i>Lock all inbound';
                    $btn_class   = $all_locked ? 'btn-outline-warning' : 'btn-outline-primary';

                    $lock_summary = '';
                    if ($some_locked && !$all_locked) {
                        $lock_summary = ' <small class="text-muted ms-1">(' . $g['locked'] . '/' . $g['total'] . ' locked)</small>';
                    }

                    echo '<tr class="table-light border-top-2" style="border-top:2px solid #dee2e6">';
                    echo '<td colspan="9" class="py-1 px-3 d-flex align-items-center">';
                    echo '<span class="fw-semibold text-primary me-2">';
                    echo '◀ Inbound ' . PluginNetstatconnectionsPort::getBadge($svcPort, $proto);
                    // DB port → the clients are consuming the LOCAL DatabaseInstance;
                    // name it here since the remote column can only show the clients.
                    if (isset($db_ports[$svcPort . '_' . strtoupper($proto)])
                        && class_exists('PluginNetstatconnectionsResolver')
                        && class_exists('DatabaseInstance')) {
                        if (!array_key_exists($svcPort, $local_inst_cache)) {
                            $local_inst_cache[$svcPort] = PluginNetstatconnectionsResolver::resolveToInstance(
                                'Computer', $computers_id, $svcPort
                            );
                        }
                        if ($local_inst_cache[$svcPort]) {
                            $li = new DatabaseInstance();
                            if ($li->getFromDB((int)$local_inst_cache[$svcPort]['id'])) {
                                echo ' <span class="fw-normal">on <a href="' . $li->getLinkURL() . '">'
                                   . htmlspecialchars($li->getName()) . '</a></span>';
                            }
                        }
                    }
                    echo ' — ' . $g['total'] . ' client' . ($g['total'] > 1 ? 's' : '');
                    echo $lock_summary;
                    echo '</span>';
                    echo '<button class="btn btn-sm ' . $btn_class . ' ms-auto netstat-bulk-lock"'
                        . ' data-computers-id="' . $computers_id . '"'
                        . ' data-local-port="' . $svcPort . '"'
                        . ' data-protocol="' . htmlspecialchars($proto) . '"'
                        . ' data-locked="' . $btn_locked . '">'
                        . $btn_label . '</button>';
                    echo '</td></tr>';
                }
            } elseif ($dir !== 'in') {
                $prev_gkey = null;   // reset so next inbound group gets a header
            }

            // Direction arrow
            $dir_icon  = ($dir === 'in')
                ? '<span title="Inbound" style="color:#007bff;font-size:1.1em">◀</span>'
                : '<span title="Outbound" style="color:#28a745;font-size:1.1em">▶</span>';

            // Port badge
            $badge = PluginNetstatconnectionsPort::getBadge($svcPort, $proto);

            // Remote display
            $remote_display = htmlspecialchars($conn['remote_hostname'] ?: $conn['remote_addr']);
            $remote_items_id = (int)($conn['remote_items_id'] ?? 0);
            $remote_itemtype = $conn['remote_itemtype'] ?? '';
            if ($remote_items_id > 0 && $remote_itemtype) {
                $item_obj = new $remote_itemtype();
                if ($item_obj->getFromDB($remote_items_id)) {
                    $remote_display = '<a href="' . $item_obj->getLinkURL() . '">'
                        . htmlspecialchars($item_obj->getName()) . '</a>';

                    // For DatabaseInstance, append the host server name
                    if ($remote_itemtype === 'DatabaseInstance') {
                        $host_type = $item_obj->fields['itemtype'] ?? '';
                        $host_id   = (int)($item_obj->fields['items_id'] ?? 0);
                        // Fallback: some GLPI versions use computers_id directly
                        if ($host_id === 0) {
                            $host_type = 'Computer';
                            $host_id   = (int)($item_obj->fields['computers_id'] ?? 0);
                        }
                        if ($host_id > 0 && $host_type) {
                            $host_obj = new $host_type();
                            if ($host_obj->getFromDB($host_id)) {
                                $remote_display .= ' <small class="text-muted">on '
                                    . '<a href="' . $host_obj->getLinkURL() . '">'
                                    . htmlspecialchars($host_obj->getName())
                                    . '</a></small>';
                            }
                        }
                    }
                }
            }

            // Scope badge
            $scope = $conn['remote_scope'] ?? '';
            $scope_badge = '';
            if ($scope === 'internal') {
                $scope_badge = ' <span class="badge bg-success" style="font-size:0.7em">INT</span>';
            } elseif ($scope === 'external') {
                $scope_badge = ' <span class="badge bg-warning" style="font-size:0.7em">EXT</span>';
            }

            // Age
            $age = '';
            if (!empty($conn['created_at'])) {
                $created = strtotime($conn['created_at']);
                if ($created) {
                    $diff = time() - $created;
                    if ($diff >= 86400) $age = floor($diff / 86400) . 'd';
                    elseif ($diff >= 3600) $age = floor($diff / 3600) . 'h';
                    else $age = max(1, floor($diff / 60)) . 'm';
                }
            }

            // Impact direction toggle button
            $impact_btn = '';
            if ($locked) {
                $is_impacts = ($direction === 'impacts');
                $btn_color  = $is_impacts ? '#dc3545' : '#007bff';
                $btn_arrow  = $is_impacts ? '→' : '←';
                $btn_title  = $is_impacts ? 'Impacts (click to toggle)' : 'Depends (click to toggle)';
                $impact_btn = '<button class="btn btn-sm netstat-direction-toggle" '
                    . 'data-id="' . $id . '" '
                    . 'data-computers-id="' . $computers_id . '" '
                    . 'data-direction="' . $direction . '" '
                    . 'style="color:#fff;background:' . $btn_color . ';border:none;min-width:36px;font-weight:bold" '
                    . 'title="' . $btn_title . '">'
                    . $btn_arrow . '</button>';
            }

            echo '<tr' . ($locked ? ' class="table-active"' : '') . '>';

            // Lock toggle
            echo '<td>'
                . '<div class="form-check form-switch mb-0">'
                . '<input type="checkbox" role="switch" class="form-check-input netstat-lock"'
                . ' data-id="' . $id . '"'
                . ' data-computers-id="' . $computers_id . '"'
                . ' data-remote-addr="' . htmlspecialchars($conn['remote_addr']) . '"'
                . ($locked ? ' checked' : '')
                . '>'
                . '</div>'
                . '</td>';

            echo '<td>' . $dir_icon . '</td>';
            echo '<td>' . $badge . '</td>';
            echo '<td>' . $remote_display . $scope_badge . '</td>';

            // Process cell — enriched from GLPI's native process inventory.
            // Shows process name + the owning user (the "Running as" win);
            // the launching command line appears on hover. The command text
            // originates from GLPI core (glpi_items_processes); we apply a
            // light display-time redaction so obvious credentials in args
            // aren't splashed into a tooltip.
            $proc_name = htmlspecialchars($conn['process_name'] ?? '');
            $proc_user = trim((string)($conn['proc_user'] ?? ''));
            $proc_cmd  = trim((string)($conn['proc_cmd']  ?? ''));
            $proc_cell = '<small>' . $proc_name . '</small>';
            if ($proc_user !== '') {
                $proc_cell .= '<br><small class="text-muted" style="font-size:0.75em">'
                    . '<i class="ti ti-user me-1"></i>' . htmlspecialchars($proc_user) . '</small>';
            }
            $cmd_attr = '';
            if ($proc_cmd !== '') {
                $safe_cmd = preg_replace(
                    '/(?i)(-P|-p|--password[= ]|password[= ]|pwd[= ]|secret[= ]|token[= ]|api[_-]?key[= ])\s*\S+/',
                    '$1 ***', $proc_cmd
                );
                $cmd_attr = ' title="' . htmlspecialchars($safe_cmd) . '" style="cursor:help"';
            }
            echo '<td' . $cmd_attr . '>' . $proc_cell . '</td>';

            echo '<td><small class="text-muted">' . $age . '</small></td>';

            // ── Weight / observation-consistency ratio (v2.9.1) ───────────
            // True ratio: seen_count ÷ cycles_observed (the host's most-seen
            // edge ≈ total collection cycles). Self-calibrating across push
            // frequencies — "seen 167/168 cycles" — and far more honest than the
            // old absolute buckets. A freshly-appeared edge reads low until it
            // proves itself over cycles (correct: a new dependency is genuinely
            // less established). The raw count + first-seen age stay in the
            // tooltip as evidence.
            $seen  = (int)($conn['seen_count'] ?? 1);
            $denom = max($seen, $cycles_observed);            // never > 100%
            $ratio = $denom > 0 ? ($seen / $denom) : 1.0;
            $pct   = (int)round($ratio * 100);
            if      ($ratio >= 0.90) { $w_label = 'Persistent'; $w_class = 'bg-success'; }
            elseif  ($ratio >= 0.50) { $w_label = 'Frequent';   $w_class = 'bg-info'; }
            elseif  ($ratio >= 0.15) { $w_label = 'Occasional'; $w_class = 'bg-secondary'; }
            else                     { $w_label = 'Rare';       $w_class = 'bg-warning text-dark'; }
            $w_title = 'Seen ' . $seen . ' of ~' . $denom . ' observed cycles (' . $pct . '%)';
            if (!empty($conn['first_seen'])) {
                $fs = strtotime((string)$conn['first_seen']);
                if ($fs) {
                    $fd = time() - $fs;
                    $fa = $fd >= 86400 ? floor($fd / 86400) . 'd'
                        : ($fd >= 3600 ? floor($fd / 3600) . 'h' : max(1, floor($fd / 60)) . 'm');
                    $w_title .= ' · first seen ' . $fa . ' ago';
                }
            }
            echo '<td><span class="badge ' . $w_class . '" style="cursor:help;font-size:0.7em" title="'
                . htmlspecialchars($w_title) . '">' . $seen . '/' . $denom . '</span>'
                . ' <small class="text-muted" style="font-size:0.7em">' . $w_label . '</small></td>';

            echo '<td><small class="text-muted">' . (int)($conn['conn_count'] ?? 1) . '</small></td>';
            echo '<td>' . $impact_btn . '</td>';

            echo '</tr>';
        }

        echo '</tbody></table></div></div>';

        // ── JavaScript ───────────────────────────────────────────────

        echo '<script>
        const _lockUrl     = ' . json_encode($lock_url)      . ';
        const _bulkLockUrl = ' . json_encode($bulk_lock_url) . ';

        document.querySelectorAll(".netstat-lock").forEach(cb => {
            cb.addEventListener("change", function() {
                const id      = this.dataset.id;
                const cid     = this.dataset.computersId;
                const addr    = this.dataset.remoteAddr;
                const locked  = this.checked ? 1 : 0;
                fetch(_lockUrl + "?id=" + id + "&locked=" + locked
                    + "&computers_id=" + cid + "&remote_addr=" + encodeURIComponent(addr) + "&ajax=1")
                    .then(r => r.json())
                    .then(() => location.reload());
            });
        });

        document.querySelectorAll(".netstat-direction-toggle").forEach(btn => {
            btn.addEventListener("click", function() {
                const id   = this.dataset.id;
                const cid  = this.dataset.computersId;
                const curr = this.dataset.direction;
                const next = (curr === "impacts") ? "depends" : "impacts";
                fetch(_lockUrl + "?id=" + id + "&locked=1"
                    + "&computers_id=" + cid + "&direction=" + next + "&change_direction=1&ajax=1")
                    .then(r => r.json())
                    .then(() => location.reload());
            });
        });

        document.querySelectorAll(".netstat-bulk-lock").forEach(btn => {
            btn.addEventListener("click", function() {
                const cid    = this.dataset.computersId;
                const port   = this.dataset.localPort;
                const proto  = this.dataset.protocol;
                const lock   = this.dataset.locked;
                const label  = this.innerHTML;

                this.disabled   = true;
                this.innerHTML  = \'<span class="spinner-border spinner-border-sm me-1"></span>Working…\';

                fetch(_bulkLockUrl
                    + "?computers_id=" + cid
                    + "&local_port="   + port
                    + "&protocol="     + encodeURIComponent(proto)
                    + "&locked="       + lock
                    + "&ajax=1")
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert("Bulk lock error: " + (data.error || "unknown"));
                            this.disabled  = false;
                            this.innerHTML = label;
                        }
                    })
                    .catch(err => {
                        alert("Request failed: " + err);
                        this.disabled  = false;
                        this.innerHTML = label;
                    });
            });
        });
        </script>';
    }
}
