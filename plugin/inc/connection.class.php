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

    public static function showForComputer(Computer $computer): void {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_netstatconnections_connections')) {
            echo '<div class="alert alert-warning m-3">Plugin table missing — please run Update.</div>';
            return;
        }

        $computers_id = (int) $computer->getID();

        // Last collection timestamp
        $last = $DB->request([
            'SELECT' => new \Glpi\DBAL\QueryExpression('MAX(`collected_at`) AS `last_collected`'),
            'FROM'   => 'glpi_plugin_netstatconnections_connections',
            'WHERE'  => ['computers_id' => $computers_id],
        ])->current();
        $last_ts = $last['last_collected'] ?? null;

        // Get known port numbers for IN clause
        $known_ports = PluginNetstatconnectionsPort::getKnownPortNumbers();
        $ephemeral   = 49152;

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
                    MAX(created_at) AS created_at,
                    COUNT(*) AS conn_count,
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
                    MAX(created_at) AS created_at,
                    COUNT(*) AS conn_count,
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
                    MAX(created_at) AS created_at,
                    COUNT(*) AS conn_count,
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
                    MAX(created_at) AS created_at,
                    COUNT(*) AS conn_count,
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

        // ── Render HTML ──────────────────────────────────────────────

        $lock_url      = Plugin::getWebDir('netstatconnections') . '/front/lock.php';
        $bulk_lock_url = Plugin::getWebDir('netstatconnections') . '/front/bulk_lock.php';

        // ── Pre-compute inbound groups for bulk-lock headers ─────────
        // Key: direction|protocol|service_port  → total / locked counts
        $group_meta = [];
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
                    echo '<td colspan="8" class="py-1 px-3 d-flex align-items-center">';
                    echo '<span class="fw-semibold text-primary me-2">';
                    echo '◀ Inbound ' . PluginNetstatconnectionsPort::getBadge($svcPort, $proto);
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
            echo '<td><small>' . htmlspecialchars($conn['process_name'] ?? '') . '</small></td>';
            echo '<td><small class="text-muted">' . $age . '</small></td>';
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
