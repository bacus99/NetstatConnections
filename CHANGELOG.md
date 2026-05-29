# Changelog

## [2.5.0] — 2026-05-29

### Dependency Map + Relation Types editor (recovered + adapted to GLPI 11.0.7)

The `Dependency Map` and `Relation Types` buttons on the Port Definitions page threw Symfony "No route found". **Root cause:** the pages backing them (`graph.php`, `relationtype.php`, `vis-asset.php`, the `relationtype` class, and the bundled vis-network library) were never committed — they existed only in the working tree, and a GitHub Desktop `git stash` swept them out, so a later deploy shipped a tree without them. The 2.4.x ledger work was unrelated. These files have now been **recovered from the stash and committed**, and adapted to GLPI 11.0.7.

**Dependency Map — `front/graph.php`.** Full interactive force-directed graph (vis-network) of all locked active connections with resolved remote CIs:
- Nodes = Computer / Cluster / DatabaseInstance (shape + colour per type); edges directed by `impact_direction`, labelled by port, coloured by relation-type (falling back to the port badge colour).
- Toolbar: port filter, host search/focus, edge-label toggle, fit, physics re-enable; click-through to the GLPI CI; node/edge counts.

**vis-network — bundled locally, no CDN at runtime.** The minified library (`vis-network.min.js` / `.css`) is now committed under `plugin/front/lib/` and served through `front/vis-asset.php`, a hard-coded **allowlist** passthrough (only those two filenames — no path-traversal, addressing the security review). `graph.php` falls back to a CDN with offline instructions only if the local files are somehow missing. Default path keeps topology rendering fully on-prem.

**Relation Types — `front/relationtype.php` + `.form.php` + `inc/relationtype.class.php`.** A `CommonDropdown` editor for relation-type labels (Database / Replicates / Authenticates …) with a colour picker + swatch rendering. Registered again now that real files back it.

**GLPI 11.0.7 adaptation:** all three recovered pages had the legacy `include('../../../inc/includes.php')` bootstrap that breaks under 11.0.7's symlinked routing; swapped to `require_once __DIR__ . '/../inc/_bootstrap.php'` (the realpath-based helper).

**Also:** `front/port.php` re-adds both buttons; the orphaned-registration error from 2.4.x is resolved (the `relationtypes` table was always fine — only the missing class/pages were the problem).

⚠️ **Deploy:** copy `front/graph.php`, `front/vis-asset.php`, `front/relationtype.php`, `front/relationtype.form.php`, `inc/relationtype.class.php`, `front/lib/vis-network.min.{js,css}`, and updated `setup.php` + `port.php`; trigger the plugin upgrade; clear cache; restart php-fpm. `php -l` each PHP file first. The two `lib/` files are binary-ish (minified) — copy them verbatim (no re-encoding).

## [2.4.0] — 2026-05-29

### Long-tail capture + edge weighting (competitive feature #1)

The strategic differentiator vs. agentless/periodic discovery (Device42-powered Freshservice, etc.): **we stop wiping the dependency history on every push, and we weight each edge by how consistently it's observed.**

**Persistence model change — accumulating edge ledger.** `push.php` previously did DELETE-all-then-INSERT per push, so any dependency not present in the current snapshot vanished — the same blind spot a periodic agentless scan has. It now **accumulates**:

- Each push collapses raw sockets into unique **edges** (`edge_key` = `SHA1(computers_id|protocol|direction|service_port|remote_addr|process_name)`, deliberately excluding the ephemeral port so 500 source sockets to one DB collapse to one edge).
- For each edge it **UPSERTs**: bump `last_seen` + `seen_count` if seen before, else INSERT with `seen_count=1`, `first_seen=now`.
- Edges absent from a push are **retained** (not deleted) and only marked `closed` by the lifecycle cron once stale — so the 3am batch job's DB connection survives instead of being wiped between scans.
- Locked rows are handled by the same UPSERT (lock/impact/resolution columns never overwritten), so the separate locked-row pass is gone.

**Edge weighting.** New `seen_count` drives a **Weight** column in the Connections tab: `Persistent` / `Frequent` / `Occasional` / `Rare`. A **Rare** (seen-once) edge is precisely the transient dependency a snapshot tool misses — surfaced in amber. Tooltip shows the raw observation count + first-seen age as honest evidence. No competitor in the mid-market exposes per-edge observation-frequency confidence.

**Schema (additive, low-risk).** New columns `edge_key CHAR(40)`, `seen_count`, `first_seen`, `conn_count` + `(computers_id, edge_key)` index. Migration backfills `edge_key`/`first_seen` for existing rows using a SQL formula byte-identical to push.php's. **No UNIQUE constraint / no one-shot prod dedupe** — pre-2.4.0 duplicate raw rows simply go stale and are purged by the cleanup cron; the UI `GROUP BY` collapses them in the meantime. This deliberately avoids a risky bulk migration on the live table.

**Scope note.** This delivers *server-side accumulation* + weighting — already a real gain (yesterday's now-absent dependency is retained, not wiped). The deeper long-tail capture (agent sub-sampling between pushes to catch sub-minute connections) is a planned follow-on agent enhancement; the server ledger is the foundation it will feed.

⚠️ **Deploy: test on a DB clone first.** This changes the core write path. Validate on a copy, then deploy plugin files + trigger the plugin upgrade (adds columns + backfills `edge_key`), clear cache, restart php-fpm.

## [2.3.0] — 2026-05-29

### Process correlation on dependency edges (competitive feature #2)

Every connection edge can now show **which process owns it and which user account it runs as** — e.g. `SHFXSQL02:1433 ← svc_payroll@DOMAIN via sqlcmd.exe`. This is the link no agentless competitor (Device42/Freshservice, iTop, JSM, SysAid) draws: they inventory processes and connections separately; we tie the process to the dependency it's responsible for.

**Correlate, don't duplicate.** GLPI Agent already reports the full process table natively to `glpi_items_processes` (PID, command, user, started, memory). Rather than re-collect and re-store command lines — which would copy any credentials embedded in process args into our table — we store **only the PID** on each connection and read user/command from GLPI's native table at display time. No command-line text is duplicated into the plugin's schema, so no new credential-leak surface.

- **`hook.php`**: added `process_pid`, `process_started`, `session_id` columns (+ `process_pid` index) to the connections table, in both `CREATE TABLE` and the upgrade column-add path.
- **`push.php`**: persists `pid` / `process_started` / `session_id` (already present in the agent cache — so existing 2.2.x agents get PID correlation with **no agent redeploy**). Refreshes them on locked rows too, so a locked edge doesn't carry a stale PID after the process restarts.
- **`connection.class.php`**: new `buildProcessMap()` reads `glpi_items_processes` for the computer (filtered `is_deleted=0`, most-recent `started` wins on PID reuse) and enriches each edge. The Process column now shows the owning **user** inline and the launching **command** on hover (display-time redaction strips obvious `-P`/`password=`/`token=` args). Degrades to PID-only, then to plain process name, if process inventory is absent.
- **`glpi-netstat-collect.pl`**: added an *isolated* second `wmic ProcessId,CreationDate` pass that captures process start time (CIM_DATETIME → `Y-m-d H:i:s`) for reuse-proof `(pid, started)` correlation. Kept separate from the main process parser so a failure can't break process collection.

**Deploy note:** the server-side half (schema + push.php + display) delivers the feature immediately for hosts already on collector 2.2.x, because `pid`/`session_id` were already in the cache. The collector 2.3.0 update only adds `process_started` (correlation hardening) — optional, deploy at leisure.

## [2.2.2] — 2026-05-27

### Locale-independent datetime serialization

#### Agent — `glpi-netstat-collect.pl`
Powershell's `Get-NetTCPConnection.CreationTime` / `Get-NetUDPEndpoint.CreationTime` are `[datetime]` objects. When piped through `Select-Object | ConvertTo-Csv` they're serialized using the system's current culture, which on en-US produces `"2026-05-10 10:02:05 AM"` — a 12-hour-with-meridiem format that MySQL strict mode rejects (`SQLSTATE[22007] Invalid datetime format`).

Fixed by projecting `CreationTime` through a calculated property that forces ISO format before the CSV stage:

```powershell
@{Name='CreationTime'; Expression={
    if ($_.CreationTime) { $_.CreationTime.ToString('yyyy-MM-dd HH:mm:ss') } else { '' }
}}
```

Same projection applied to both the TCP and UDP collection blocks.

#### Server — `push.php`
Added `normalize_datetime()` helper that parses any reasonable datetime string (24h ISO, 12h with AM/PM, M/D/YYYY locale variants, ISO-8601 with `T` separator) through `strtotime()` + `date('Y-m-d H:i:s')`, producing canonical MySQL TIMESTAMP input. Used for both `$collected` (per-push) and `$created_at` (per-row). Defends against any future agent or future locale that sneaks an unexpected datetime format past the agent fix.

#### Server — `agentconfig.php`
Rewritten as standalone (raw PDO from `/etc/glpi*/config_db.php`) — was failing with `Class "PluginNetstatconnectionsAgentconfig" not found` followed by `Call to a member function tableExists() on null` because GLPI 11.0.7's `LegacyFileLoadController` no longer autoloads plugin classes nor bootstraps `$DB` for plugin file requests. Mirrors `push.php`'s approach for consistent reliability.

#### Server — `setup.php`
Only `push.php` is registered as stateless now. `agentconfig.php` and `vis-asset.php` removed from the stateless list because they're GET-only (already CSRF-exempt via `CheckCsrfListener`'s bodyless-method early-return) and registering them as stateless was preventing GLPI from loading the plugin's autoloader and `$DB` for those requests — defeating the helper class approach that the admin UI still relies on.

### Required server-side operational fix
The agent's `Connections module` 404 errors were ultimately caused by **missing symlinks** under `<glpi_root>/public/`:

```bash
sudo ln -s ../plugins      /usr/share/glpi/public/plugins
sudo ln -s ../marketplace  /usr/share/glpi/public/marketplace
sudo chown -h apache:apache /usr/share/glpi/public/plugins /usr/share/glpi/public/marketplace
sudo systemctl reload httpd
```

Without these, Apache serves its own 404 (HTML) for plugin URLs before the request reaches PHP — making the 404 look identical to "plugin not loaded" but actually originating one layer up. The diagnostic giveaway: response has `Server: Apache/...` + `Content-Type: text/html` (not `application/json` from GLPI) and no entry appears in `/var/log/glpi*/access-errors.log`.

## [2.2.1] — 2026-05-27

### GLPI 11.0.7 compatibility

GLPI 11.0.7 split the request gating into two parallel listeners:

1. **`FirewallStrategyListener`** (authentication) — consults `Firewall::STRATEGY_*` AND `SessionManager::isResourceStateless()`
2. **`CheckCsrfListener`** (CSRF protection, NEW in 11.0.7) — consults **only** `SessionManager::isResourceStateless()`

Before 11.0.7 our `Firewall::addPluginStrategyForLegacyScripts(..., STRATEGY_NO_CHECK)` calls bypassed both auth AND CSRF. In 11.0.7 they only bypass auth. Result: every agent POST to `push.php` failed CSRF and returned 404 (under default Symfony "hide endpoint" behavior).

**Fix**: `setup.php` now also registers our 3 unauthenticated endpoints (`push.php`, `agentconfig.php`, `vis-asset.php`) as **stateless paths** via `\Glpi\Http\SessionManager::registerPluginStatelessPath()`. The previous concern that caused us to remove this registration in 2.1.x — `$DB` being null in push.php — no longer applies because push.php uses raw PDO and bootstraps its own DB connection from `/etc/glpi11/config_db.php`.

### Symptom that prompted the fix
- `[error] Connections module: push failed 404 Not Found from .../plugins/netstatconnections/front/push.php`
- GLPI access-errors.log: `CSRF check failed for User ID: Anonymous at /glpi/plugins/netstatconnections/front/push.php` with backtrace through `CheckCsrfListener->onKernelController()`

### Operational note: symlinks required under `public/`

GLPI 11 serves all HTTP requests from `<glpi_root>/public/` (Symfony docroot). For URLs under `/plugins/<plugin>/...` and `/marketplace/<plugin>/...` to reach PHP at all, Apache needs symlinks:

```
<glpi_root>/public/plugins      -> ../plugins
<glpi_root>/public/marketplace  -> ../marketplace
```

Without these, Apache returns its own default 404 page (plain HTML, not Symfony's response) **before** the request reaches PHP-FPM, so the CSRF fix in `setup.php` never gets a chance to run. The 404 looks identical to a plugin-disabled 404 but the diagnostic giveaway is `Server: Apache/...` + `Content-Type: text/html` in the response headers (not `application/json`) and the absence of any new entry in `/var/log/glpi*/access-errors.log` after a failed request.

GLPI's RPM packaging usually creates these symlinks on install but they can be lost on major version upgrades or manual reinstalls. Recreate with:

```bash
for link in plugins marketplace; do
  if [[ ! -L /usr/share/glpi/public/$link ]]; then
    sudo ln -s ../$link /usr/share/glpi/public/$link
    sudo chown -h apache:apache /usr/share/glpi/public/$link
  fi
done
sudo systemctl reload httpd
```

Recommend adding this snippet to your post-upgrade automation.

## [2.2.0] — 2026-05-15

### Plugin
- **New cron `NetstatLifecycle`** (hourly): transitions active→closed for rows whose `last_seen` is older than `param` hours. Default 72h = 3× the standard 24h agent push cycle, sized to absorb jitter without flapping. Pass 2 sweeps stale agents (no push in 7+ days) and bulk-closes their remaining active rows.
- **New cron `NetstatEnrich`** (hourly): soft-populates `service_port`, `conn_direction`, `impact_direction` on unlocked active rows from port definitions. Never overwrites existing values. Drives dependency-map fill-in without manual locking.
- **`autolock.class.php` v1.3.0 — Cluster-aware impact routing**: when a `DatabaseInstance` is hosted on a `Cluster` (AlwaysOn / FCI), the client edge now routes `Source → Cluster → DBI` instead of bypassing the Cluster. Legacy direct `Source ↔ DBI` edges from previous versions are removed on next lock to migrate the graph cleanly. Multi-port aggregation (`MSSQL 1433, AlwaysOn 5022`) on the cluster edge.
- **`push.php`**: now bumps `last_seen` + flips `connection_status='active'` on still-reported locked rows, preventing the lifecycle cron from closing them.
- **`push.php`**: `$collected` is now validated against `^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}` and `0000-` prefix — `??` only catches `null`, so empty-string `collected_at` from the agent could previously plant `'0000-00-00 00:00:00'` into `collected_at` and `last_seen` columns on every push.
- **`agentconfig.class.php`**: removed 3 diagnostic `error_log` calls left from debugging.

### MySQL 8.x compatibility & datetime hardening
- **`crontask.class.php`**: lifecycle / cleanup / stale-agent queries rewritten to use SQL-native `DATE_SUB(NOW(), INTERVAL N HOUR|DAY)` instead of PHP `strtotime()` + literal interpolation. Eliminates the failure mode where `strtotime` returned an empty string under certain locale / PHP-version combos, producing `HAVING MAX(last_seen) < ''` and triggering 1292 warnings. Also adds `IFNULL(col + 0, 0) > 0` filters so zero-date values are excluded from aggregation.
- **`hook.php`**: install/upgrade now runs `SET SESSION explicit_defaults_for_timestamp = 1` before any TIMESTAMP-column ALTER. Without it, MySQL silently overrides `NULL DEFAULT NULL` and forces `NOT NULL DEFAULT '0000-00-00 00:00:00'` on legacy servers, planting zero-dates in every existing row.
- **`hook.php`**: detects the silent-override case (`NOT NULL` + `DEFAULT '0000-00-00 00:00:00'`) and auto-repairs by dropping and re-adding the `created_at` column with the session variable set. Loss of legacy `created_at` data is acceptable — only the UI "age" column depends on it.
- **`hook.php`**: unconditional cleanup pass scrubs invalid datetime values across all 3 plugin tables (connections, ports, relationtypes — 8 columns total) using `(col + 0) = 0` arithmetic identification (avoids the 1525 literal-value-rejection error on TIMESTAMP comparisons with `'0000-00-00 00:00:00'`).
- **`connection.class.php`**: added `selfHealDatetimes()` — self-heals invalid datetime rows the first time the Connections tab is rendered each PHP request. Temporarily clears `sql_mode` for the cleanup, then restores. Static flag prevents repeated work within the same request.

### Agent
- **`Connections.pm`**: fixed URL normalization producing `https://host/glpiplugins/...` instead of `https://host/glpi/plugins/...` (trailing-slash regex strip was running after canonical slash was added).
- **`MSSQL.pm`**: hardened against Always On secondary replicas — `sp_spaceused` and `sys.objects` queries returning `undef` no longer trigger "uninitialized value in pattern match" / "int(undef)" warnings on cluster nodes. `$starttime =~ s/...` also got an `if defined()` guard.
- **User-Agent bump** to `GLPI-Agent-NetstatConnections/2.2.0`.

### Manual one-shot DB cleanup (MySQL 8.x users on existing installs)
If you're upgrading from <=2.1.x on MySQL 8.x and seeing `1292: Incorrect datetime value: ''` warnings on the Connections tab, the auto-repair in `hook.php` runs on plugin update. If you need to fix it without redeploying:

```sql
SET SESSION explicit_defaults_for_timestamp = 1;
ALTER TABLE glpi_plugin_netstatconnections_connections DROP COLUMN created_at;
ALTER TABLE glpi_plugin_netstatconnections_connections ADD COLUMN created_at TIMESTAMP NULL DEFAULT NULL AFTER connection_status;

-- Verify
SHOW CREATE TABLE glpi_plugin_netstatconnections_connections;
-- Expect: `created_at` timestamp NULL DEFAULT NULL
-- NOT:    `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00' (silent-override case)
```

## [1.3.0] — 2026-04-26

### Plugin — Pillar 1: Connection Lifecycle
- **`connection.class.php`**: Fixed `$seen_ids` array initialization (was undefined before loop — PHP notice on every push)
- **`connection.class.php`**: UPDATE path now also refreshes `local_addr`, `local_port`, `conn_direction`, `collection_method` on existing rows (previously only state/hostname/service_name)
- **`connection.class.php`**: INSERT path now includes `conn_direction` (was missing — caused NULLs)
- **`connection.class.php`**: All 4 UNION display queries now filter `AND connection_status = 'active'` — closed rows no longer clutter the tab
- **`connection.class.php`**: Card header shows "X closed" badge when vanished connections exist
- **`push.php`**: Full rewrite — POST-only, App-Token validation against `glpi_apiclients`, session resume, JSON validation, try/catch around `handleInventory()`, returns stats (pushed/elapsed_ms/active/closed/locked)
- **`setup.php`**: Version bump to 1.3.0

### Agent — Bulk Push Mode
- **Collector v2.1.0**: New `push_mode` config (`bulk` default, `rest` fallback)
- **`_pushViaBulkPS()`**: Windows bulk push — single POST of full JSON payload to `push.php` instead of N individual REST API calls + delete loop
- **`_pushViaBulkCurl()`**: Linux/curl equivalent
- **`netstat-collect.ini`**: Added `push_mode = bulk` with documentation
- **`Version.pm`**: Bumped to 1.3.0

### Data Flow Change
- **Before (v1.2)**: Agent → initSession → DELETE unlocked rows (N calls) → INSERT each row (N calls) → killSession. Vanished connections = gone forever.
- **After (v1.3)**: Agent → initSession → POST full payload to push.php (1 call) → killSession. Vanished connections marked `closed`, purged by cron after retention period.

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
