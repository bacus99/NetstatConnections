<?php
/**
 * PluginNetstatconnectionsPort
 * Port definitions with visual rendering in search list.
 *
 * v1.2.0 — Fixes:
 *   - Port numbers: 'specific' datatype avoids thousand separator (1 433 → 1433)
 *   - Color: rendered as colored circle swatch instead of hex code
 *   - Auto-lock: checkmark icon instead of Yes/No
 *   - Direction: arrow badge instead of plain text
 */
class PluginNetstatconnectionsPort extends CommonDropdown {

    static $rightname = 'dropdown';

    public static function getTypeName($nb = 0) {
        return _n('Port Definition', 'Port Definitions', $nb, 'netstatconnections');
    }

    public static function getIcon() {
        return 'ti ti-plug';
    }

    public static function getMenuContent() {
        $menu = [
            'title'   => __('Network Connections', 'netstatconnections'),
            'page'    => '/plugins/netstatconnections/front/port.php',
            'icon'    => 'ti ti-plug',
            'options' => [
                'port' => [
                    'title' => __('Port Definitions', 'netstatconnections'),
                    'page'  => '/plugins/netstatconnections/front/port.php',
                    'links' => [
                        'add'    => '/plugins/netstatconnections/front/port.form.php',
                        'search' => '/plugins/netstatconnections/front/port.php',
                    ],
                ],
            ],
        ];
        return $menu;
    }

    // ── Search options ───────────────────────────────────────────────
    // CommonDropdown reserves IDs 1 (id), 2 (name), 16 (comment).
    // Custom fields start at 10. Use 'specific' datatype for visual columns.

    public function rawSearchOptions() {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'            => 10,
            'table'         => self::getTable(),
            'field'         => 'port_number',
            'name'          => __('Port Number'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'contains'],
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => 11,
            'table'    => self::getTable(),
            'field'    => 'protocol',
            'name'     => __('Protocol'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'            => 12,
            'table'         => self::getTable(),
            'field'         => 'color',
            'name'          => __('Color'),
            'datatype'      => 'specific',
            'searchtype'    => ['contains'],
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 13,
            'table'         => self::getTable(),
            'field'         => 'auto_lock',
            'name'          => __('Auto-Lock'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals'],
            'massiveaction' => true,
        ];
        $tab[] = [
            'id'            => 14,
            'table'         => self::getTable(),
            'field'         => 'auto_direction',
            'name'          => __('Direction'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals'],
            'massiveaction' => true,
        ];
        $tab[] = [
            'id'            => 15,
            'table'         => self::getTable(),
            'field'         => 'is_database_port',
            'name'          => __('DB Port'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals'],
            'massiveaction' => true,
        ];

        return $tab;
    }

    // ── Custom rendering for search results list ─────────────────────

    public static function getSpecificValueToDisplay($field, $values, array $options = []) {
        if (!isset($values[$field])) {
            return parent::getSpecificValueToDisplay($field, $values, $options);
        }

        $val = $values[$field];

        switch ($field) {
            case 'port_number':
                // Plain number, no thousand separator
                return '<span style="font-family:monospace;font-weight:600">' . (int)$val . '</span>';

            case 'color':
                // Colored circle + hex code
                $hex = htmlspecialchars($val);
                $text = self::contrastColor($val);
                return '<span style="display:inline-flex;align-items:center;gap:6px">'
                     . '<span style="display:inline-block;width:22px;height:22px;'
                     . 'border-radius:50%;background:' . $hex . ';'
                     . 'border:1px solid rgba(0,0,0,0.15)"></span>'
                     . '<code style="font-size:11px;color:#888">' . $hex . '</code>'
                     . '</span>';

            case 'auto_lock':
                if ((int)$val) {
                    return '<span style="color:#198754" title="Auto-lock enabled">'
                         . '<i class="ti ti-lock" style="font-size:16px"></i>'
                         . '</span>';
                }
                return '<span style="color:#ccc" title="Manual only">'
                     . '<i class="ti ti-lock-open" style="font-size:16px"></i>'
                     . '</span>';

            case 'auto_direction':
                if ($val === 'impacts') {
                    return '<span class="badge" style="background:#dc3545;color:#fff;font-size:11px">'
                         . '<i class="ti ti-arrow-right"></i> impacts</span>';
                } elseif ($val === 'depends') {
                    return '<span class="badge" style="background:#0d6efd;color:#fff;font-size:11px">'
                         . '<i class="ti ti-arrow-left"></i> depends</span>';
                }
                return '<span class="text-muted">—</span>';

            case 'is_database_port':
                if ((int)$val) {
                    return '<span style="color:#dc3545" title="Database port — resolves to DatabaseInstance">'
                         . '<i class="ti ti-database" style="font-size:16px"></i>'
                         . '</span>';
                }
                return '<span style="color:#ccc" title="Not a database port">'
                     . '<i class="ti ti-database-off" style="font-size:16px"></i>'
                     . '</span>';
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    // ── Custom input for search criteria ─────────────────────────────

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = []) {
        switch ($field) {
            case 'auto_lock':
                return Dropdown::showFromArray(
                    $name,
                    [0 => __('No'), 1 => __('Yes')],
                    ['display' => false, 'value' => $values[$field] ?? '']
                );

            case 'auto_direction':
                return Dropdown::showFromArray(
                    $name,
                    ['impacts' => 'Impacts', 'depends' => 'Depends'],
                    ['display' => false, 'value' => $values[$field] ?? '']
                );

            case 'port_number':
                return Html::input($name, [
                    'value' => $values[$field] ?? '',
                    'type'  => 'number',
                    'size'  => 6,
                    'display' => false,
                ]);

            case 'is_database_port':
                return Dropdown::showFromArray(
                    $name,
                    [0 => __('No'), 1 => __('Yes')],
                    ['display' => false, 'value' => $values[$field] ?? '']
                );
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Get port definitions keyed by port_number_PROTOCOL and bare port number.
     */
    public static function getPortDefinitions(): array {
        global $DB;
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [];
        $iter = $DB->request(['FROM' => self::getTable()]);
        foreach ($iter as $row) {
            $key = (int)$row['port_number'] . '_' . strtoupper($row['protocol']);
            $cache[$key] = $row;
            // Fallback key by bare port number (first match wins)
            if (!isset($cache[$row['port_number']])) {
                $cache[$row['port_number']] = $row;
            }
        }
        return $cache;
    }

    /**
     * Get badge HTML for a port in the connections tab.
     */
    public static function getBadge(int $port, string $protocol = 'TCP'): string {
        $defs = self::getPortDefinitions();
        $key  = $port . '_' . strtoupper($protocol);
        $def  = $defs[$key] ?? $defs[$port] ?? null;

        if ($def) {
            $bg    = htmlspecialchars($def['color'] ?? '#6c757d');
            $text  = self::contrastColor($bg);
            $label = htmlspecialchars($def['name'] ?? strtoupper($protocol) . ' ' . $port);
            return '<span class="badge" style="background:' . $bg . ';color:' . $text
                 . ';font-size:11px;padding:3px 8px">' . $label . ' ' . $port . '</span>';
        }

        // Unknown port — grey badge
        return '<span class="badge bg-secondary text-white" style="font-size:11px;padding:3px 8px">'
             . strtoupper($protocol) . ' ' . $port . '</span>';
    }

    /**
     * Get label string for a port (used in impact relation names).
     */
    public static function getBadgeLabel(int $port, string $protocol = 'TCP'): string {
        $defs = self::getPortDefinitions();
        $key  = $port . '_' . strtoupper($protocol);
        $def  = $defs[$key] ?? $defs[$port] ?? null;
        if ($def) {
            return $def['name'] . ' ' . $port;
        }
        return strtoupper($protocol) . ' ' . $port;
    }

    /**
     * Return black or white depending on background luminance.
     */
    public static function contrastColor(string $hex): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0] . $hex[1].$hex[1] . $hex[2].$hex[2];
        }
        if (strlen($hex) !== 6) return '#000';
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        return $lum > 0.55 ? '#000' : '#fff';
    }

    /**
     * Return array of known port numbers for display filtering.
     */
    public static function getKnownPortNumbers(): array {
        $defs = self::getPortDefinitions();
        $ports = [];
        foreach ($defs as $key => $row) {
            if (is_numeric($key)) continue; // skip bare-port fallback keys
            $ports[] = (int)$row['port_number'];
        }
        return array_unique($ports);
    }

    // ── Form ─────────────────────────────────────────────────────────

    public function showForm($ID, array $options = []) {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        // Row 1: Port + Protocol
        echo '<tr><td>' . __('Port Number') . '</td><td>';
        echo Html::input('port_number', [
            'value' => $this->fields['port_number'] ?? '',
            'type'  => 'number',
            'min'   => 1,
            'max'   => 65535,
        ]);
        echo '</td><td>' . __('Protocol') . '</td><td>';
        Dropdown::showFromArray('protocol', ['TCP' => 'TCP', 'UDP' => 'UDP'], [
            'value' => $this->fields['protocol'] ?? 'TCP',
        ]);
        echo '</td></tr>';

        // Row 2: Name + Color
        echo '<tr><td>' . __('Name') . '</td><td>';
        echo Html::input('name', ['value' => $this->fields['name'] ?? '']);
        echo '</td><td>' . __('Color') . '</td><td>';
        $color = htmlspecialchars($this->fields['color'] ?? '#6c757d');
        echo '<input type="color" name="color" value="' . $color . '" '
           . 'style="width:60px;height:32px;border:1px solid #ccc;border-radius:4px;cursor:pointer">';
        echo ' <code style="font-size:11px;color:#888">' . $color . '</code>';
        echo '</td></tr>';

        // Row 3: Auto-lock + Direction
        echo '<tr><td>' . __('Auto-Lock') . '</td><td>';
        Dropdown::showFromArray('auto_lock', [0 => __('No'), 1 => __('Yes')], [
            'value' => $this->fields['auto_lock'] ?? 0,
        ]);
        echo '</td><td>' . __('Direction') . '</td><td>';
        Dropdown::showFromArray('auto_direction', [
            'impacts' => __('Impacts (→)'),
            'depends' => __('Depends (←)'),
        ], [
            'value' => $this->fields['auto_direction'] ?? 'impacts',
        ]);
        echo '</td></tr>';

        // Row 4: Database port flag
        echo '<tr><td>' . __('Database Port') . '</td><td>';
        Dropdown::showFromArray('is_database_port', [0 => __('No'), 1 => __('Yes')], [
            'value' => $this->fields['is_database_port'] ?? 0,
        ]);
        echo '</td><td colspan="2"><small class="text-muted">'
           . __('Resolve to DatabaseInstance in impact analysis', 'netstatconnections')
           . '</small></td></tr>';

        // Row 4: Database Port
        echo '<tr><td>' . __('Database Port') . '</td><td>';
        Dropdown::showFromArray('is_database_port', [
            0 => __('No'),
            1 => __('Yes — resolve to DatabaseInstance'),
        ], [
            'value' => $this->fields['is_database_port'] ?? 0,
        ]);
        echo '</td><td colspan="2"><em class="text-muted">'
           . 'When locked, impact targets DatabaseInstance instead of Computer'
           . '</em></td></tr>';

        // Row 5: Comment
        echo '<tr><td>' . __('Comments') . '</td><td colspan="3">';
        echo '<textarea name="comment" rows="3" class="form-control">'
           . htmlspecialchars($this->fields['comment'] ?? '')
           . '</textarea>';
        echo '</td></tr>';

        $this->showFormButtons($options);

        return true;
    }
}
