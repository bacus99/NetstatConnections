# Changelog

## [1.2.0] — 2026-04-22

### Plugin
- **Inbound auto-lock fix**: policy matcher now explicitly detects inbound vs outbound. `local_port` match + `conn_direction='inbound'` → direction = `depends` (they depend on us)
- **Port Definitions visual overhaul**: `specific` datatype eliminates thousand separators on port numbers (1 433 → 1433), color swatches instead of hex codes, lock/unlock icons, direction arrow badges
- **Default sort** by port number ASC
- **DB schema**: added `last_seen` TIMESTAMP, `connection_status` ENUM(active/closed), MySQL-compatible index creation via `INFORMATION_SCHEMA.STATISTICS`
- **Cron NetstatCleanup**: daily purge of unlocked closed connections past retention (default 30 days, `param = 0` to keep forever)
- **Fixed**: duplicate `src/` code path killed (was doing blanket delete bypassing merge logic)
- **Fixed**: nested `netstatconnections/netstatconnections/` directory cleanup
- **Fixed**: extra `}` brace in connection.class.php, `getTabNameForItem` restored after accidental removal
- **Fixed**: `getKnownPortNumbers()` method added to port.class.php

### Agent
- **Collector v2.0**: delete+insert via REST API confirmed as stable pattern
- **Direction detection**: ephemeral port analysis determines inbound vs outbound
- **created_at preservation**: locked row ages survive delete cycles

### Known Limitations
- Connection lifecycle (mark closed instead of delete) parked — REST API generic path does raw INSERT, merge logic requires custom Symfony-routed endpoint (v1.3)
- `push.php` is a v1.3 placeholder, returns 501

## [1.1.2] — 2026-04-21

### Plugin
- Lock by IP only — removed `remote_port` from WHERE clause (locks all connections from same remote)
- `port.class.php` → `rightname = 'dropdown'` (fixes inability to add ports)
- `resolver.class.php` → `resolved_via = 'unresolved'` not `'none'` (ENUM validation)

### Agent
- WMIC CSV right-to-left parsing: fixes comma-in-CommandLine bug (Chrome args like `--field-trial-handle=2040,262144`)
- Fields parsed right-to-left: `WorkingSetSize`, `SessionId`, `ProcessId` are always clean numerics at the end

## [1.1.0] — 2026-04-20

### Plugin
- Cluster-first resolver: hostname → `glpi_clusters` before `glpi_computers`
- Impact direction: outbound = `impacts`, inbound = `depends`
- Direction toggle button on locked connections (click to flip impacts↔depends)
- `conn_direction` column (inbound/outbound) in connections table
- Cron `NetstatResolveAll` for background IP resolution (every 30 min)
- Impact relation supports Computer → Cluster (not just Computer → Computer)
- Fix: impact removal respects direction (removes only the exact direction edge, not both)

## [1.0.7] — 2026-04-16

### Plugin
- Port extends `CommonDropdown`, appears in Setup → Dropdowns
- `plugin_dropdown_tabs` hook registered in setup.php
- Inbound/outbound direction arrows in tab display

## [1.0.5] — 2026-04-15

### Plugin
- Auto-lock engine (`autolock.class.php`) — policies on port definitions trigger automatic locking
- `auto_lock` + `auto_direction` columns on ports table
- Cron `NetstatAutoLock` sweep + call from inventory handler
- Impact Analysis integration: `ImpactItem` + `ImpactRelation` created on lock

## [1.0.1] — 2026-04-14

### Plugin
- `is_locked` column: locked rows survive delete/insert cycle
- Lock checkbox in tab display
- Plugin icon (`ti ti-network`)
- Port Definitions table with color picker

## [1.0.0] — 2026-04-13

### Initial Release
- Agent: `glpi-netstat-collect.pl` — `netstat -ano` + WMIC process enrichment
- Agent: REST API push to GLPI (per-connection INSERT)
- Plugin: "Network Connections" tab on Computer
- Plugin: Port definitions with colors
- Plugin: Process names, hostnames, badge display
- Plugin: Filter box
