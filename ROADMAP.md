# Roadmap — v3.x

State after v2.8.0: edge ledger + weighting, process/user context on edges,
built-in CI mapping (printers / network gear / all DB engines), dependency map,
relation types, topology drift, appliance-level dependency rollup. The
observed-traffic intelligence layer is built; v3 is about **trust, depth, and
workflows** on top of it.

Effort: S (≤1 day) · M (days) · L (week+). Value relative to competitors
(Device42/Freshservice, iTop, JSM, SysAid) noted where relevant.

---

## v3.0 — "Trust & Depth" (recommended scope)

The plugin is now load-bearing; v3.0 closes the security review and completes
the long-tail moat.

| # | Item | Effort | Why |
|---|------|--------|-----|
| 1 | **Per-host push tokens (security H1)** — `token = HMAC(server_secret, hostname)`; `agentconfig.php` issues only the requesting host's token; `push.php` validates per-host. Kills the "one public token authorizes everyone" hole — a leaked token authorizes one host, not the fleet. | M | The #1 finding from the security review; data-poisoning protection. |
| 2 | **Agent sub-sampling (long-tail completion)** — collector runs every 5–15 min (scheduled task), unions edges into a local accumulation cache with per-edge counters; the daily push sends accumulated observations. `seen_count` becomes hour-granular and the 3am 30-second batch job is *caught*, not lucked into. | M/L | Completes the structural moat: agentless tools poll snapshots; we observe continuously. The single biggest capability gap left. |
| 3 | **CI lint gate** — GitHub Actions workflow running `php -l` (and the SQL-comment-quote grep) on every push. | S | The v2.7 parse error took the whole plugin down; this class of bug becomes impossible to ship. Do this first. |
| 4 | ~~**True weight ratio**~~ — ✅ **shipped v2.9.1**. Uses the host's most-seen edge as the cycle denominator ("seen 167/168") rather than a stored push counter — zero-migration, works on existing data. | S | Makes the Weight column honest and self-calibrating across different push frequencies. |
| 5 | ~~**Reappear drift events**~~ — ✅ **shipped v2.9.0** (`reopened_at` stamp + `reappeared` event type). | S | Closes the v2.7 scope gap; flapping dependencies become fully visible. |
| 6 | **Security M-series** — lock/bulk_lock to POST+CSRF (M1); generic error bodies on unauthenticated endpoints, details to server log (M3); optional agent TLS verification via CA bundle/pinned fingerprint (M2). | M | Rounds out the review. M2 pairs naturally with #1 (token actually secret again). |

## v3.1 — "Workflows" (turn data into operations)

| # | Item | Effort | Why |
|---|------|--------|-----|
| 7 | **Drift → GLPI notifications** — notification event on `disappeared` for locked edges (or members of selected appliances); admins subscribe via GLPI's normal notification system. | M | Drift becomes alerting, not just a review page. |
| 8 | **Migration verification** — "Snapshot dependencies" button on a Computer; after migration, "Verify" reports which observed dependencies have not re-established. | M | The workflow nobody in the mid-market packages; extremely sticky for infra teams. |
| 9 | **Appliance graph mode** — `graph.php?appliance=N`: members + their direct neighbors only. | S/M | Makes the per-appliance view visual; cheap reuse of the existing graph. |
| 10 | **More dependency tabs** — "Used by" tab on DatabaseInstance (who uses this DB), NetworkEquipment, Printer. Same derivation, different anchor. | S | Spreads the value across CI pages people already visit. |

## v3.2 — "Reach" (new signal sources)

| # | Item | Effort | Why |
|---|------|--------|-----|
| 11 | **Firewall reconciliation** — agent collects Windows Firewall rules; server diffs observed traffic vs allowed rules: undocumented-but-working paths, and dead rules (allowed, never observed in 90d). | M/L | Security-audit capability essentially absent at mid-market. |
| 12 | **External/SaaS dependency mapping (SNI)** — capture TLS SNI for outbound 443 → "this host depends on these 12 external services". | L | Shadow-IT / third-party-risk discovery; thin everywhere. |
| 13 | **Linux agent parity** — certify + package the collector's existing Linux paths (`ss`/`netstat`, `/proc`) for the Linux fleet. | M | Coverage breadth; the server side is already OS-agnostic. |

## Known gaps (from testing phase)

- **Inbound-on-unknown-port blind spot** — the Connections-tab UNION only has an
  "unknown" branch for OUTBOUND; inbound traffic to a service port that has no
  port definition is collected but never displayed (client port is ephemeral so
  no branch matches). Workaround: define the port. Fix: add a 4th UNION branch
  (`local_port < ephemeral AND remote_port >= ephemeral AND local_port NOT IN known`).
- **Old impact relations after the 2.8.1 semantics fix** — relations created
  pre-2.8.1 keep their arrows until the edge is relocked/toggled. Possible v3
  one-shot: a maintenance action that rebuilds all relations from locked rows.

## Housekeeping (fold into any release)

- Remove dead `inc/inventoryhandler.class.php` (superseded by push architecture).
- Commit the agent deploy script to the repo.
- Commit discipline: the v2.5 "files lost to a stash" incident — everything that
  ships must be committed, and the CI lint (#3) only protects committed code.
- Document the Apache `public/` rewrite + symlink requirements in INSTALL notes
  (currently only in CHANGELOG 2.2.2) — required knowledge for any new GLPI host.

## Suggested v3.0 cut

**#3 (CI lint) → #1 (per-host tokens) → #4 (weight ratio) → #5 (reappear) → #2 (sub-sampling) → #6 (M-series).**
Lint first because it protects everything after it; tokens before sub-sampling
because more valuable data deserves a real auth boundary; sub-sampling last in
the cut because it's the only item requiring a fleet-wide agent redeploy — ship
it once, alongside the M2 TLS option, in a single agent rollout.
