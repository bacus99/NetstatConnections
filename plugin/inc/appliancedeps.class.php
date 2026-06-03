<?php
/**
 * PluginNetstatconnectionsAppliancedeps
 *
 * Adds a "Network Dependencies" tab to GLPI's native Appliance — NO custom
 * grouping entity. Membership is read straight from GLPI core's
 * glpi_appliances_items, so this reuses the grouping admins already maintain
 * and adds only the one thing GLPI lacks: an observed-traffic dependency
 * rollup for the appliance's members.
 *
 *   DEPENDS ON : a member computer connects out to a CI owned by another
 *                appliance (or an unassigned CI).
 *   USED BY    : a computer in another appliance connects in to a member CI.
 *
 * Everything is derived live from the connection ledger — nothing stored.
 */
class PluginNetstatconnectionsAppliancedeps extends CommonGLPI {

    const CONN_TABLE = 'glpi_plugin_netstatconnections_connections';
    const APPL_ITEMS = 'glpi_appliances_items';
    const APPL_TABLE = 'glpi_appliances';

    public static function getIcon() {
        return 'ti ti-topology-star-3';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item instanceof Appliance && $item->getID() > 0) {
            // Pass itemtype + icon so GLPI 11 renders the tab icon.
            return self::createTabEntry(
                __('Network Dependencies', 'netstatconnections'),
                0,
                static::class,
                static::getIcon()
            );
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item instanceof Appliance) {
            self::showForAppliance($item);
        }
        return true;
    }

    // ── Membership (from GLPI core glpi_appliances_items) ─────────────────

    /** Members of an appliance, enriched with name/link. */
    public static function getMembers(int $appliances_id): array {
        global $DB;
        if ($appliances_id <= 0 || !$DB->tableExists(self::APPL_ITEMS)) return [];

        $out = [];
        foreach ($DB->request([
            'FROM'  => self::APPL_ITEMS,
            'WHERE' => ['appliances_id' => $appliances_id],
        ]) as $row) {
            $itemtype = (string)$row['itemtype'];
            $items_id = (int)$row['items_id'];
            $name = $itemtype . ' #' . $items_id;
            $link = null;
            if (class_exists($itemtype)) {
                $obj = new $itemtype();
                if ($obj->getFromDB($items_id)) {
                    $name = $obj->getName();
                    $link = $obj->getLinkURL();
                }
            }
            $out[] = ['itemtype' => $itemtype, 'items_id' => $items_id, 'name' => $name, 'link' => $link];
        }
        return $out;
    }

    /** Reverse: which appliances own a given CI (cached). */
    public static function getAppliancesForItem(string $itemtype, int $items_id): array {
        global $DB;
        static $cache = [];
        if ($itemtype === '' || $items_id <= 0) return [];
        $key = $itemtype . ':' . $items_id;
        if (isset($cache[$key])) return $cache[$key];

        $ids = [];
        if ($DB->tableExists(self::APPL_ITEMS)) {
            foreach ($DB->request([
                'SELECT' => ['appliances_id'],
                'FROM'   => self::APPL_ITEMS,
                'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $items_id],
            ]) as $r) {
                $ids[] = (int)$r['appliances_id'];
            }
        }
        return $cache[$key] = $ids;
    }

    /** Appliance id → name map. */
    public static function nameMap(): array {
        global $DB;
        static $map = null;
        if ($map !== null) return $map;
        $map = [];
        if ($DB->tableExists(self::APPL_TABLE)) {
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => self::APPL_TABLE]) as $r) {
                $map[(int)$r['id']] = $r['name'];
            }
        }
        return $map;
    }

    // ── Derived dependencies ──────────────────────────────────────────────

    public static function getDependencies(int $appliances_id, array $members): array {
        global $DB;
        $result = ['depends_on' => [], 'used_by' => []];
        if (empty($members) || !$DB->tableExists(self::CONN_TABLE)) return $result;

        $member_keys = [];
        $member_computer_ids = [];
        $members_by_type = [];
        foreach ($members as $m) {
            $member_keys[$m['itemtype'] . ':' . $m['items_id']] = true;
            $members_by_type[$m['itemtype']][] = (int)$m['items_id'];
            if ($m['itemtype'] === 'Computer') {
                $member_computer_ids[] = (int)$m['items_id'];
            }
        }

        // DEPENDS ON — member computers → non-member resolved CIs
        if (!empty($member_computer_ids)) {
            $idlist = implode(',', array_map('intval', $member_computer_ids));
            $q = $DB->doQuery(
                "SELECT remote_itemtype, remote_items_id, protocol, service_port,
                        MAX(remote_hostname) AS remote_hostname, MAX(remote_addr) AS remote_addr
                 FROM `" . self::CONN_TABLE . "`
                 WHERE connection_status = 'active'
                   AND remote_items_id > 0
                   AND remote_itemtype IS NOT NULL
                   AND computers_id IN ({$idlist})
                 GROUP BY remote_itemtype, remote_items_id, protocol, service_port
                 LIMIT 5000"
            );
            $agg = [];
            while ($r = $DB->fetchAssoc($q)) {
                $key = $r['remote_itemtype'] . ':' . (int)$r['remote_items_id'];
                if (isset($member_keys[$key])) continue;
                if (!isset($agg[$key])) {
                    $agg[$key] = [
                        'itemtype' => $r['remote_itemtype'],
                        'items_id' => (int)$r['remote_items_id'],
                        'label'    => $r['remote_hostname'] ?: $r['remote_addr'],
                        'app_ids'  => self::getAppliancesForItem($r['remote_itemtype'], (int)$r['remote_items_id']),
                        'ports'    => [],
                    ];
                }
                $p = trim(($r['protocol'] ?? '') . ' ' . (int)$r['service_port']);
                if ($p !== '' && !in_array($p, $agg[$key]['ports'], true)) $agg[$key]['ports'][] = $p;
            }
            $result['depends_on'] = array_values($agg);
        }

        // USED BY — non-member computers → member CIs
        $target_clauses = [];
        foreach ($members_by_type as $itemtype => $ids) {
            $idlist = implode(',', array_map('intval', $ids));
            $target_clauses[] = "(remote_itemtype = '" . $DB->escape($itemtype) . "' AND remote_items_id IN ({$idlist}))";
        }
        if (!empty($target_clauses)) {
            $target_sql = '(' . implode(' OR ', $target_clauses) . ')';
            $exclude = !empty($member_computer_ids)
                ? ' AND computers_id NOT IN (' . implode(',', array_map('intval', $member_computer_ids)) . ')'
                : '';
            $q = $DB->doQuery(
                "SELECT computers_id, protocol, service_port
                 FROM `" . self::CONN_TABLE . "`
                 WHERE connection_status = 'active'
                   AND {$target_sql}
                   {$exclude}
                 GROUP BY computers_id, protocol, service_port
                 LIMIT 5000"
            );
            $agg = [];
            while ($r = $DB->fetchAssoc($q)) {
                $cid = (int)$r['computers_id'];
                if ($cid <= 0) continue;
                if (!isset($agg[$cid])) {
                    $agg[$cid] = [
                        'computers_id' => $cid,
                        'app_ids'      => self::getAppliancesForItem('Computer', $cid),
                        'ports'        => [],
                    ];
                }
                $p = trim(($r['protocol'] ?? '') . ' ' . (int)$r['service_port']);
                if ($p !== '' && !in_array($p, $agg[$cid]['ports'], true)) $agg[$cid]['ports'][] = $p;
            }
            $result['used_by'] = array_values($agg);
        }

        return $result;
    }

    // ── Render ────────────────────────────────────────────────────────────

    public static function showForAppliance(Appliance $appliance): void {
        global $DB;
        $id      = (int)$appliance->getID();
        $members = self::getMembers($id);

        if (empty($members)) {
            echo '<div class="alert alert-info m-3"><i class="ti ti-info-circle me-1"></i>'
               . __('This appliance has no member items yet. Add CIs via the appliance\'s items tab; their observed network dependencies will appear here.', 'netstatconnections')
               . '</div>';
            return;
        }

        $deps   = self::getDependencies($id, $members);
        $appmap = self::nameMap();

        $render_apps = function (array $app_ids) use ($appmap, $id) {
            $chips = [];
            foreach ($app_ids as $aid) {
                if ($aid === $id || !isset($appmap[$aid])) continue;
                $url = Appliance::getFormURLWithID($aid);
                $chips[] = '<a href="' . $url . '" class="badge bg-primary text-decoration-none">'
                         . htmlspecialchars($appmap[$aid]) . '</a>';
            }
            return $chips ? implode(' ', $chips) : '<span class="text-muted">' . __('Unassigned', 'netstatconnections') . '</span>';
        };

        // computer names for used_by
        $used_cids = array_values(array_filter(array_map(fn($u) => (int)$u['computers_id'], $deps['used_by'])));
        $cnames = [];
        if ($used_cids) {
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_computers', 'WHERE' => ['id' => $used_cids]]) as $c) {
                $cnames[(int)$c['id']] = $c['name'];
            }
        }

        echo '<div class="row m-2">';

        // DEPENDS ON — outbound: members consume these remote services, so this
        // appliance depends on them (they impact it).
        echo '<div class="col-md-6"><div class="card"><div class="card-header"><h3 class="card-title mb-0">'
           . '<i class="ti ti-arrow-right me-2"></i>' . __('Depends on', 'netstatconnections')
           . ' <span class="badge bg-secondary">' . count($deps['depends_on']) . '</span></h3></div><div class="card-body">';
        echo '<p class="text-muted small">' . __('Services outside this appliance that its members connect out to — if they fail, this appliance is impacted.', 'netstatconnections') . '</p>';
        if (empty($deps['depends_on'])) {
            echo '<p class="text-muted mb-0">' . __('None observed.', 'netstatconnections') . '</p>';
        } else {
            echo '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>'
               . '<th>' . __('Remote CI') . '</th><th>' . __('Appliance', 'netstatconnections') . '</th><th>' . __('Service', 'netstatconnections') . '</th></tr></thead><tbody>';
            foreach ($deps['depends_on'] as $d) {
                $label = htmlspecialchars($d['label'] ?: ($d['itemtype'] . ' #' . $d['items_id']));
                if (class_exists($d['itemtype'])) {
                    $o = new $d['itemtype']();
                    if ($o->getFromDB($d['items_id'])) $label = '<a href="' . $o->getLinkURL() . '">' . htmlspecialchars($o->getName()) . '</a>';
                }
                echo '<tr><td>' . $label . ' <small class="text-muted">(' . htmlspecialchars($d['itemtype']) . ')</small></td>'
                   . '<td>' . $render_apps($d['app_ids']) . '</td>'
                   . '<td><small class="text-muted">' . htmlspecialchars(implode(', ', $d['ports'])) . '</small></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div></div>';

        // USED BY — inbound: outside computers consume our members' services, so
        // they depend on this appliance (it impacts them).
        echo '<div class="col-md-6"><div class="card"><div class="card-header"><h3 class="card-title mb-0">'
           . '<i class="ti ti-arrow-left me-2"></i>' . __('Used by', 'netstatconnections')
           . ' <span class="badge bg-secondary">' . count($deps['used_by']) . '</span></h3></div><div class="card-body">';
        echo '<p class="text-muted small">' . __('Computers outside this appliance that consume its members\' services — if this appliance fails, they are impacted.', 'netstatconnections') . '</p>';
        if (empty($deps['used_by'])) {
            echo '<p class="text-muted mb-0">' . __('None observed.', 'netstatconnections') . '</p>';
        } else {
            echo '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>'
               . '<th>' . __('Source computer', 'netstatconnections') . '</th><th>' . __('Appliance', 'netstatconnections') . '</th><th>' . __('Service', 'netstatconnections') . '</th></tr></thead><tbody>';
            foreach ($deps['used_by'] as $u) {
                $cid   = (int)$u['computers_id'];
                $cname = $cnames[$cid] ?? ('#' . $cid);
                $clink = '<a href="' . Computer::getFormURLWithID($cid) . '">' . htmlspecialchars($cname) . '</a>';
                echo '<tr><td>' . $clink . '</td>'
                   . '<td>' . $render_apps($u['app_ids']) . '</td>'
                   . '<td><small class="text-muted">' . htmlspecialchars(implode(', ', $u['ports'])) . '</small></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div></div>';

        echo '</div>';
    }
}
