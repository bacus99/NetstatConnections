<?php
/**
 * PluginNetstatconnectionsGraphtab
 *
 * Adds a "Dependency Graph" tab to Computer and Appliance that embeds the same
 * interactive Network Dependency Map (graph.php) — scoped to that CI's
 * neighbourhood — via an iframe in focus+embed mode. Reuses the full renderer
 * (aggregation, weight styling, min-weight slider, blast-radius hover, side
 * panel) rather than duplicating it.
 *
 *   Computer  → its outbound edges + inbound edges from other computers.
 *   Appliance → its members' edges (uses PluginNetstatconnectionsAppliancedeps).
 */
class PluginNetstatconnectionsGraphtab extends CommonGLPI {

    public static function getIcon() {
        return 'ti ti-topology-star-3';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if (($item instanceof Computer || $item instanceof Appliance) && $item->getID() > 0) {
            return self::createTabEntry(
                __('Dependency Graph', 'netstatconnections'),
                0,
                static::class,
                static::getIcon()
            );
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        $id = (int)$item->getID();
        if ($id <= 0) {
            return false;
        }

        $src = Plugin::getWebDir('netstatconnections', true) . '/front/graph.php'
             . '?embed=1'
             . '&for_itemtype=' . rawurlencode(get_class($item))
             . '&for_items_id=' . $id;

        echo '<div class="netstat-graph-tab" style="margin:-5px">';
        echo '<iframe src="' . htmlspecialchars($src) . '" '
           . 'style="width:100%;height:74vh;border:0;display:block;background:#f8f9fa" '
           . 'loading="lazy" '
           . 'title="' . __('Dependency Graph', 'netstatconnections') . '"></iframe>';
        echo '</div>';
        return true;
    }
}
