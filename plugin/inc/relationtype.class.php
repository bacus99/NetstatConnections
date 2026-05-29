<?php
/**
 * PluginNetstatconnectionsRelationtype
 *
 * Managed dropdown of semantic relationship types (Database, Replicates,
 * Authenticates, …).  Each type has a name and a display color.
 *
 * Relation types are assigned to port definitions; the graph and impact
 * relation names use them to convey the nature of a connection rather
 * than just its port number.
 */
class PluginNetstatconnectionsRelationtype extends CommonDropdown {

    static $rightname = 'dropdown';

    public static function getTypeName($nb = 0) {
        return _n('Relation Type', 'Relation Types', $nb, 'netstatconnections');
    }

    public static function getIcon() {
        return 'ti ti-arrow-fork';
    }

    // ── Search options ────────────────────────────────────────────────

    public function rawSearchOptions() {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'            => 10,
            'table'         => self::getTable(),
            'field'         => 'color',
            'name'          => __('Color'),
            'datatype'      => 'specific',
            'searchtype'    => ['contains'],
            'massiveaction' => false,
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = []) {
        if ($field === 'color' && isset($values['color'])) {
            $hex = htmlspecialchars($values['color']);
            return '<span style="display:inline-flex;align-items:center;gap:6px">'
                 . '<span style="display:inline-block;width:22px;height:22px;border-radius:50%;'
                 . 'background:' . $hex . ';border:1px solid rgba(0,0,0,0.15)"></span>'
                 . '<code style="font-size:11px;color:#888">' . $hex . '</code>'
                 . '</span>';
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    // ── Form ─────────────────────────────────────────────────────────

    public function showForm($ID, array $options = []) {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo '<tr><td>' . __('Name') . '</td><td>';
        echo Html::input('name', ['value' => $this->fields['name'] ?? '']);
        echo '</td><td>' . __('Color') . '</td><td>';
        $color = htmlspecialchars($this->fields['color'] ?? '#6c757d');
        echo '<input type="color" name="color" value="' . $color . '" '
           . 'style="width:60px;height:32px;border:1px solid #ccc;border-radius:4px;cursor:pointer"> '
           . '<code style="font-size:11px;color:#888">' . $color . '</code>';
        echo '</td></tr>';

        echo '<tr><td>' . __('Comments') . '</td><td colspan="3">';
        echo '<textarea name="comment" rows="3" class="form-control">'
           . htmlspecialchars($this->fields['comment'] ?? '')
           . '</textarea>';
        echo '</td></tr>';

        $this->showFormButtons($options);
        return true;
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Return all relation types keyed by id, for use in dropdowns and graph.
     */
    public static function getAll(): array {
        global $DB;
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [];
        $iter  = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['is_deleted' => 0]]);
        foreach ($iter as $row) {
            $cache[(int)$row['id']] = $row;
        }
        return $cache;
    }
}
