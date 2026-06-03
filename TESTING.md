# Testing checklist — v2.8.0 batch (covers 2.4.0 → 2.8.0)

This batch changes the **core write path** (edge ledger), adds 1 table, 2 crons,
4 UI surfaces, and 3 schema column groups. Verify in the order below — the core
pipeline first, features second. Rollback is cheap (see §6) because all schema
changes are additive.

---

## 0. Pre-flight (before touching anything)

- [ ] **Backup the plugin tables**
  ```bash
  mysqldump prd_glpi \
    glpi_plugin_netstatconnections_connections \
    glpi_plugin_netstatconnections_ports \
    glpi_plugin_netstatconnections_relationtypes \
    glpi_plugin_netstatconnections_config \
    > /root/netstat_pre28_$(date +%F).sql
  ```
- [ ] **Snapshot the deployed plugin dir**
  ```bash
  tar czf /root/netstatconnections_pre28.tgz -C /usr/share/glpi/plugins netstatconnections
  ```
- [ ] **Record the baseline** (compare after deploy)
  ```sql
  SELECT COUNT(*) AS total,
         SUM(is_locked=1) AS locked,
         SUM(connection_status='active') AS active
  FROM glpi_plugin_netstatconnections_connections;
  ```
- [ ] Confirm at least one agent is currently pushing OK (`glpi-agent.log`:
      `Connections module: pushed N connections`).

## 1. Deploy

- [ ] Copy ALL plugin files, **including `front/lib/vis-network.min.{js,css}`**
      (binary-ish — copy verbatim, no re-encoding).
- [ ] **Remove deleted files** on the server:
  ```bash
  sudo rm -f /usr/share/glpi/plugins/netstatconnections/inc/application.class.php \
             /usr/share/glpi/plugins/netstatconnections/front/application.php \
             /usr/share/glpi/plugins/netstatconnections/front/application.form.php
  ```
- [ ] `chown -R apache:apache`, dirs 755 / files 644.
- [ ] **Lint everything ON THE SERVER** (mandatory — this caught the v2.7 parse error):
  ```bash
  cd /usr/share/glpi/plugins/netstatconnections
  find . -name '*.php' -not -path './front/lib/*' | while read f; do
    printf '%s: ' "$f"; php -l "$f" 2>&1 | tail -1
  done
  ```
  **Every line must say "No syntax errors detected" before proceeding.**
- [ ] Setup → Plugins → Network Connections → **Upgrade** (→ 2.8.0).
- [ ] Clear cache + restart:
  ```bash
  sudo rm -rf /usr/share/glpifiles/_cache/* && sudo systemctl restart php-fpm
  ```

### Schema verification
- [ ] New columns present:
  ```sql
  SHOW COLUMNS FROM glpi_plugin_netstatconnections_connections
  WHERE Field IN ('edge_key','seen_count','first_seen','conn_count',
                  'closed_at','process_pid','process_started','session_id');
  -- expect 8 rows
  ```
- [ ] Drift table exists: `SHOW TABLES LIKE '%netstatconnections_drift';`
- [ ] Backfill complete:
  ```sql
  SELECT COUNT(*) AS total, SUM(edge_key IS NULL) AS missing_key,
         SUM(first_seen IS NULL) AS missing_first
  FROM glpi_plugin_netstatconnections_connections;
  -- missing_key and missing_first must be 0
  ```
- [ ] Crons registered: Setup → Automatic actions → six `Netstat*` tasks
      (ResolveAll, AutoLock, Enrich, Lifecycle, Cleanup, **Drift**).

## 2. Core pipeline — CRITICAL (the write path changed in 2.4.0)

- [ ] Endpoint sanity:
  ```bash
  curl -s -o /dev/null -w "push: %{http_code}\n" -X POST -H "Content-Type: application/json" -d '{}' \
    https://glpi.transcontinental.ca/glpi/plugins/netstatconnections/front/push.php   # expect 401
  curl -s -o /dev/null -w "agentconfig: %{http_code}\n" \
    https://glpi.transcontinental.ca/glpi/plugins/netstatconnections/front/agentconfig.php  # expect 200
  ```
- [ ] Force a push on ONE test host:
  ```powershell
  & "C:\Program Files\GLPI-Agent\perl\bin\glpi-agent.exe" --debug --logger=stderr 2>&1 |
    Select-String "Connections module"
  # expect: pushed N connections, M listening ports
  ```
- [ ] **Ledger accumulates** — run the agent twice (or wait two cycles):
  ```sql
  SELECT remote_addr, service_port, process_name, seen_count, first_seen, last_seen
  FROM glpi_plugin_netstatconnections_connections
  WHERE computers_id = <test_host_id> ORDER BY seen_count DESC LIMIT 10;
  -- seen_count must INCREMENT between pushes for stable edges
  ```
- [ ] **Locked rows survived**: pick a known locked edge → `is_locked=1` intact,
      `last_seen` bumped after push, its impact relation still on the Computer's
      Impact tab.
- [ ] **No row explosion**: total row count should be ≈ distinct edges per host
      (tens-to-low-hundreds per host), NOT growing by the full connection count
      every push. Old pre-2.4.0 duplicate rows shrink over days (lifecycle+cleanup).

## 3. Features (after §2 passes)

- [ ] **Weight column** — Computer → Connections tab: badges
      (`N×` Persistent/Frequent/Occasional/Rare), tooltip shows
      "Observed in N pushes · first seen X ago".
- [ ] **Running as** — same tab: process user under the process name (on hosts
      where GLPI process inventory is enabled); command line on hover.
- [ ] **Dependency Map** — Port Definitions → Dependency Map: renders; browser
      network tab shows vis-network loading from `vis-asset.php` (NO CDN calls);
      port filter / search / labels toggle work; new CI shapes (printer triangle,
      network square) appear where applicable.
- [ ] **Relation Types** — page opens, add/edit with colour picker works.
- [ ] **Topology Drift** — Port Definitions → Topology Drift: loads. First
      `NetstatDrift` run logs nothing (baseline). Then: pick a test host, stop one
      service (or add a new connection), wait the lifecycle staleness window /
      next push → `disappeared` / `appeared` event shows; acknowledge works.
      Quick smoke without waiting: `SELECT COUNT(*) FROM glpi_plugin_netstatconnections_drift;`
      after a couple of cron cycles.
- [ ] **Appliance dependencies** — open an Appliance that has member items →
      "Network Dependencies" tab → Depends-on / Used-by populate; appliance
      chips link correctly.
- [ ] **Built-in CI mapping** — a connection to an inventoried printer/switch
      resolves to a clickable Printer/NetworkEquipment link in the Connections
      tab after the next `NetstatResolveAll` run.

## 4. First-week watch items

- [ ] **Table growth plateaus** — check daily; expect rise then steady state:
  ```sql
  SELECT COUNT(*), SUM(connection_status='active') FROM glpi_plugin_netstatconnections_connections;
  ```
- [ ] Cron health — Setup → Automatic actions: every `Netstat*` task ran
      recently without error.
- [ ] `php-fpm` / `glpi11` error logs stay clean (no new warnings).
- [ ] Drift volume sane — if hundreds of events/day, the lifecycle threshold or
      the resolved-only filter may need tuning.
- [ ] Spot-check 2–3 hosts' Connections tabs for data quality (weights climbing,
      no duplicate-looking rows after a week).

## 5. Known degradations that are EXPECTED (not bugs)

- "Age"/first_seen for pre-2.4.0 rows reflects the backfill, not true history.
- `seen_count` starts ~1 for everything; weights become meaningful after days.
- Hosts without GLPI process inventory show no "Running as" (plain process name).
- Drift shows nothing until the SECOND `NetstatDrift` run (baseline first).
- Old duplicate raw rows linger until lifecycle+cleanup purge them (≤ ~staleness
  + retention window).

## 6. Rollback (cheap — schema is additive)

Old code ignores the new columns entirely, so **restoring the old plugin files
is a complete behavioral rollback** — no schema downgrade needed:
```bash
sudo tar xzf /root/netstatconnections_pre28.tgz -C /usr/share/glpi/plugins
sudo rm -rf /usr/share/glpifiles/_cache/* && sudo systemctl restart php-fpm
```
(Resulting state: pre-2.4.0 DELETE+INSERT behavior; new columns sit unused.
The drift table and crons are inert without the new code.)
