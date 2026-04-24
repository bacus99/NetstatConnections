# GLPI Network Connections Plugin

**Version 1.2.0** — Network connection visibility, dependency mapping, and Impact Analysis integration for GLPI.

Replicates ServiceNow-style service mapping via netstat collection: shows active TCP/UDP connections per computer, resolves IPs to GLPI CIs, auto-locks dependencies, and builds Impact Analysis graphs automatically.

## Architecture

```
Windows Server                          GLPI Server
┌──────────────────┐                   ┌──────────────────────────────┐
│ glpi-netstat.bat  │    REST API      │ plugins/netstatconnections/  │
│  └─ collect.pl    │ ──────────────►  │  ├─ connection.class.php     │
│     (every 15min) │   delete+insert  │  ├─ resolver.class.php      │
│                   │                  │  ├─ autolock.class.php       │
│ netstat -ano      │                  │  └─ crontask.class.php       │
│ + PowerShell      │                  │                              │
│   process enrich  │                  │ ┌─ Impact Analysis ────────┐ │
└──────────────────┘                   │ │ Computer A → Cluster B   │ │
                                       │ │ Computer C → Computer D  │ │
                                       │ └─────────────────────────┘ │
                                       └──────────────────────────────┘
```

## Features

### Plugin (GLPI Server)

- **Network Connections tab** on every Computer — badges, process names, clickable remote hosts
- **IP → CI resolver** — maps remote IPs to Computers and Clusters via GLPI's network tables + DNS fallback
- **Lock/unlock connections** — locked rows survive the delete/insert cycle; click to toggle
- **Impact Analysis integration** — locking a connection creates `ImpactItem` + `ImpactRelation` edges
- **Direction-aware** — inbound (◀ IN) vs outbound (▶ OUT) with correct impact direction
- **Auto-lock policies** — configure Port Definitions to automatically lock connections on known ports
- **Port Definitions** — dropdown under Setup → Dropdowns with color swatches, lock icons, direction badges
- **Cron tasks** — background IP resolution, auto-lock sweep, closed connection cleanup
- **Connection age** — shows how long a connection has existed (3d, 12h, 45m)
- **Filter box** — type-to-filter across all columns

### Agent (Windows Server)

- **PowerShell-based collection** — `netstat -ano` + `Win32_Process` + `Win32_Service`
- **Process enrichment** — PID → process name, service name, creation time
- **Direction detection** — ephemeral port analysis to determine inbound vs outbound
- **Configurable filtering** — exclude processes, IPs, IPv6, loopback
- **REST API push** — authenticates via App-Token + User-Token, handles locked row protection

## Installation

### GLPI Plugin

```bash
# On the GLPI server
cd /usr/share/glpi/plugins
unzip netstatconnections.zip
chown -R apache:apache netstatconnections/

# Clear GLPI cache
rm -rf /usr/share/glpifiles/_cache/*
```

Then in GLPI: **Setup → Plugins → Network Connections → Install → Enable**

### Agent Collector

1. Copy `agent/` contents to `C:\Program Files\GLPI-Agent\`
2. Edit `etc\netstat-collect.ini` with your GLPI URL and API tokens
3. Create a Task Scheduler job to run `glpi-netstat.bat` every 15 minutes

**Getting API tokens:**
- **App-Token**: Setup → General → API → Add API client
- **User-Token**: User Preferences → Remote access keys → API token

## Configuration

### Port Definitions

Go to **Setup → Dropdowns → Port Definitions** to configure known ports:

| Port | Name | Auto-Lock | Direction |
|------|------|-----------|-----------|
| 1433 | MSSQL | Yes | impacts |
| 5022 | SQL AlwaysOn | Yes | impacts |
| 443 | HTTPS | No | — |
| 3389 | RDP | No | — |

Auto-lock means: when a connection on this port is collected, it's automatically locked and an Impact Analysis relation is created.

### Cron Tasks

Check **Administration → Cron**:

| Task | Frequency | Description |
|------|-----------|-------------|
| NetstatResolveAll | 30 min | Resolve unresolved IPs to GLPI CIs |
| NetstatAutoLock | 1 hour | Apply auto-lock policies |
| NetstatCleanup | Daily | Purge old closed connections (param = days) |

## Version History

| Version | Date | Changes |
|---------|------|---------|
| **1.2.0** | 2026-04 | Inbound auto-lock fix, Port Definitions visual overhaul, lifecycle columns (DB ready), MySQL-compatible indexes |
| **1.1.2** | 2026-04 | WMIC CSV parsing fix, lock by IP only, merge strategy preserving connection age |
| **1.1.0** | 2026-04 | Cluster-first resolver, direction toggle, CommonDropdown for ports, cron tasks |
| **1.0.5** | 2026-04 | Auto-lock engine, Impact Analysis integration, direction detection |
| **1.0.0** | 2026-04 | Initial release — tab display, REST API push, port definitions |

## Roadmap

### v1.3 — Connection Lifecycle + DatabaseInstance

- Mark vanished connections as `closed` instead of deleting (needs Symfony-routed push endpoint)
- Auto-lock resolves to `DatabaseInstance` for SQL ports (not just Computer)
- Cluster → Instance → Database chain in Impact Analysis
- Fleet rollout packaging (GPO/SCCM deployment)

### v1.4+ — Future

- Linux agent support (`ss -tunap` parsing)
- Global dependency dashboard across fleet
- Connection history view ("what changed since yesterday")
- Process → Software CI linking

## File Structure

```
plugin/netstatconnections/           ← Deploy to /usr/share/glpi/plugins/
├── setup.php                        # Plugin registration
├── hook.php                         # Install/uninstall (schema + migrations)
├── netstatconnections.xml           # Plugin descriptor
├── pics/
│   └── netstatconnections.svg       # Plugin icon
├── inc/
│   ├── connection.class.php         # Main class: tab display + merge logic
│   ├── port.class.php               # Port definitions (CommonDropdown)
│   ├── resolver.class.php           # IP → CI resolution
│   ├── autolock.class.php           # Auto-lock engine
│   ├── crontask.class.php           # Cron tasks
│   └── inventoryhandler.class.php   # Inventory hook bridge
└── front/
    ├── lock.php                     # AJAX lock/unlock/direction endpoint
    ├── port.php                     # Port definitions list
    ├── port.form.php                # Port definition form
    └── push.php                     # Bulk push (v1.3 placeholder)

agent/                               ← Deploy to C:\Program Files\GLPI-Agent\
├── glpi-netstat-collect.pl          # Main collector (974 lines, PowerShell+WMIC)
├── glpi-netstat.bat                 # Windows launcher (Task Scheduler)
├── netstat-collect.ini              # Configuration (tokens, filters, exclusions)
└── lib/GLPI/Agent/
    ├── Tools/NetStat.pm             # Reusable collection library (connections, DNS, processes)
    └── Task/
        ├── NetStat/Version.pm       # Version constant
        └── Inventory/Generic/
            └── Connections.pm       # GLPI inventory module (injects into XML pipeline)
```

## License

GPLv3
