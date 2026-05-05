#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Deploy the GLPI Agent NetStat Inventory module.

.DESCRIPTION
    Copies Connections.pm (Inventory module) and NetStat tools to the GLPI Agent,
    then creates/updates conf.d/netstat.cfg.

    IMPORTANT: This script does NOT install Task/NetStat/Version.pm.
    Installing that file would cause the GlpiInventory server to reject the
    agent contact with "netstat task not supported" (400 Bad Request), breaking
    all tasks. Collection is done inside the Inventory task via Connections.pm.

.PARAMETER PushUrl
    URL to push.php endpoint.

.PARAMETER PushToken
    Token from GLPI > Plugins > Network Connections.

.PARAMETER AgentRoot
    GLPI Agent install root. Default: C:\Program Files\GLPI-Agent

.EXAMPLE
    .\deploy-agent-module.ps1
    .\deploy-agent-module.ps1 -PushUrl "https://glpi.tc.tc/glpi/..." -PushToken "abc..."
#>
param(
    [string] $PushUrl   = '',
    [string] $PushToken = '',
    [string] $AgentRoot = 'C:\Program Files\GLPI-Agent'
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path

# ── Verify installation ────────────────────────────────────────────────────────
$taskDir    = Join-Path $AgentRoot 'perl\agent\GLPI\Agent\Task'
$toolDir    = Join-Path $AgentRoot 'perl\agent\GLPI\Agent\Tools'
$confDir    = Join-Path $AgentRoot 'etc\conf.d'

if (-not (Test-Path $taskDir)) {
    Write-Error "GLPI Agent not found at: $AgentRoot"
}

# ── Safety check: remove Version.pm if accidentally present ───────────────────
$badVersion = Join-Path $taskDir 'NetStat\Version.pm'
if (Test-Path $badVersion) {
    Remove-Item $badVersion -Force
    Write-Host "! Removed $badVersion (would break GlpiInventory contact)" -ForegroundColor Yellow
}

# ── Copy module files ──────────────────────────────────────────────────────────
$filesToCopy = @(
    # src (relative to ScriptDir)                      dest
    @('perl\agent\GLPI\Agent\Tools\NetStat.pm',        (Join-Path $toolDir 'NetStat.pm')),
    @('perl\agent\GLPI\Agent\Task\Inventory\Generic\Connections.pm',
                                                        (Join-Path $taskDir 'Inventory\Generic\Connections.pm'))
)

foreach ($pair in $filesToCopy) {
    $src = Join-Path $ScriptDir $pair[0]
    $dst = $pair[1]
    if (Test-Path $src) {
        New-Item -ItemType Directory -Force -Path (Split-Path $dst) | Out-Null
        Copy-Item -Force $src $dst
        Write-Host "OK  $(Split-Path $src -Leaf)  ->  $dst" -ForegroundColor Green
    } else {
        Write-Host "    (not found: $src)" -ForegroundColor DarkGray
    }
}

# ── Create / update netstat.cfg ────────────────────────────────────────────────
$cfgFile = Join-Path $confDir 'netstat.cfg'
New-Item -ItemType Directory -Force -Path $confDir | Out-Null

if (-not $PushUrl)   { $PushUrl   = Read-Host 'Push URL   (from GLPI > Plugins > Network Connections)' }
if (-not $PushToken) { $PushToken = Read-Host 'Push Token (from GLPI > Plugins > Network Connections)' }

@"
# NetStatConnections push configuration
# Generated $(Get-Date -Format 'yyyy-MM-dd HH:mm') by deploy-agent-module.ps1
push_url   = $PushUrl
push_token = $PushToken
"@ | Set-Content -Path $cfgFile -Encoding UTF8

Write-Host "OK  $cfgFile" -ForegroundColor Green

# ── Restart service ────────────────────────────────────────────────────────────
$svc = Get-Service -Name 'GLPI-Agent' -ErrorAction SilentlyContinue
if ($svc) {
    Write-Host "`nRestarting GLPI Agent service..." -ForegroundColor Yellow
    Restart-Service 'GLPI-Agent'
    Start-Sleep -Seconds 2
    $svc = Get-Service -Name 'GLPI-Agent'
    $color = if ($svc.Status -eq 'Running') { 'Green' } else { 'Red' }
    Write-Host "Service: $($svc.Status)" -ForegroundColor $color
} else {
    Write-Host "`nGLPI-Agent service not found — restart manually." -ForegroundColor Yellow
}

Write-Host "`nDone. Connections will be pushed on the next GLPI inventory cycle." -ForegroundColor Cyan
Write-Host "(Connections.pm runs as part of the standard Inventory task)" -ForegroundColor DarkGray
