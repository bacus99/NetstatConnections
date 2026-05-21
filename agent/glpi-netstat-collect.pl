#!/usr/bin/perl
# glpi-netstat-collect.pl v2.0.0
# Standalone NetStat data collector for GLPI Agent
#
# Windows: PowerShell Get-NetTCPConnection + Get-NetUDPEndpoint (with CreationTime)
#          Falls back to netstat -ano if PowerShell fails
#          Detects HTTP.SYS-managed ports via netsh
# Linux:   ss -tunap / netstat -tunap
#
# Writes JSON cache to GLPI Agent vardir, pushes via REST API.
#
# Usage: glpi-agent.exe glpi-netstat-collect.pl [--debug] [--vardir=PATH]

BEGIN {
    my $root = $ENV{GLPI_AGENT_ROOT} // 'C:/Program Files/GLPI-Agent';
    unshift @INC, "$root/perl/agent", "$root/perl/lib", "$root/perl/vendor/lib";
}

use strict;
use warnings;
use English      qw(-no_match_vars);
use File::Spec;
use File::Basename qw(dirname);
use POSIX        qw(strftime);
use Getopt::Long;
use Socket       qw(inet_aton AF_INET);
use JSON::PP;

our $VERSION = '2.2.0';

# ---------------------------------------------------------------------------
# Options
# ---------------------------------------------------------------------------
my $agent_root = $ENV{GLPI_AGENT_ROOT} // 'C:/Program Files/GLPI-Agent';
my $debug      = 0;
my $vardir     = "$agent_root/var";

GetOptions(
    'debug'    => \$debug,
    'vardir=s' => \$vardir,
) or die "Usage: $0 [--debug] [--vardir=PATH]\n";

sub info  { print "[info]  @_\n" }
sub dbg   { print "[debug] @_\n" if $debug }
sub err   { print "[error] @_\n" }

# ---------------------------------------------------------------------------
# INI config
# ---------------------------------------------------------------------------
my $script_dir     = dirname(File::Spec->rel2abs($0));
my $agent_root_dir = $agent_root;

my $ini_file = File::Spec->catfile($script_dir, 'netstat-collect.ini');
if (!-f $ini_file) {
    $ini_file = File::Spec->catfile($agent_root_dir, 'netstat-collect.ini');
}

my %config = (
    established_only         => 1,
    skip_ipv6                => 1,
    skip_loopback            => 1,
    ephemeral_port_threshold => 49152,
    push_enabled             => 0,
    push_mode                => 'bulk',
    glpi_url                 => '',
    app_token                => '',
    user_token               => '',
);

my @excl_procs;
my @excl_remote_ips;
my @excl_remote_ports;
my @incl_only_ips;

if (-f $ini_file) {
    info("Config loaded from: $ini_file");
    _loadINI($ini_file);
} else {
    info("No INI file found, using defaults");
}

my $hostname = $ENV{COMPUTERNAME} // do { chomp(my $h = `hostname`); $h };
my $start    = time();

info("NetStat collector v$VERSION starting on $hostname");
dbg("PowerShell mode: Get-NetTCPConnection + Get-NetUDPEndpoint");

# ---------------------------------------------------------------------------
# Auto-detect GLPI server URL if not in INI
# ---------------------------------------------------------------------------
if (!$config{glpi_url}) {
    my @agent_cfgs = (
        File::Spec->catfile($agent_root_dir, 'etc', 'agent.cfg'),
        File::Spec->catfile($agent_root_dir, 'etc', 'conf.d', 'local.cfg'),
        '/etc/glpi-agent/agent.cfg',
        '/etc/glpi-agent/conf.d/local.cfg',
    );
    for my $acf (@agent_cfgs) {
        next unless -f $acf;
        open my $afh, '<', $acf or next;
        while (<$afh>) {
            chomp;
            if (/^\s*server\s*=\s*(\S+)/i) {
                $config{glpi_url} = $1;
                $config{glpi_url} =~ s|/+$||;
                dbg("Auto-detected GLPI URL from agent config: $config{glpi_url}");
                last;
            }
        }
        close $afh;
        last if $config{glpi_url};
    }
}

# ---------------------------------------------------------------------------
# Fetch server-pushed collection config (if GLPI URL known)
# ---------------------------------------------------------------------------
if ($config{glpi_url}) {
    my $server_cfg = _fetchServerConfig($config{glpi_url}, $hostname);
    if ($server_cfg) {
        info("Using server-pushed collection settings");
        # Override collection scalars
        for my $k (qw(established_only skip_ipv6 skip_loopback ephemeral_port_threshold)) {
            if (exists $server_cfg->{$k}) {
                my $v = $server_cfg->{$k};
                $config{$k} = ref($v) ? $v
                             : ($v =~ /^(true|1)$/i) ? 1
                             : ($v =~ /^(false|0)$/i) ? 0
                             : $v;
                dbg("  $k = $config{$k}");
            }
        }
        # Override exclusion/inclusion lists
        if (exists $server_cfg->{exclude_processes}) {
            @excl_procs = @{ $server_cfg->{exclude_processes} // [] };
            dbg("  exclude_processes: " . scalar(@excl_procs) . " entries");
        }
        if (exists $server_cfg->{exclude_remote_ips}) {
            @excl_remote_ips = @{ $server_cfg->{exclude_remote_ips} // [] };
            dbg("  exclude_remote_ips: " . scalar(@excl_remote_ips) . " entries");
        }
        if (exists $server_cfg->{exclude_remote_ports}) {
            @excl_remote_ports = map { int($_) } @{ $server_cfg->{exclude_remote_ports} // [] };
            dbg("  exclude_remote_ports: " . scalar(@excl_remote_ports) . " entries");
        }
        if (exists $server_cfg->{include_only_ips}) {
            @incl_only_ips = @{ $server_cfg->{include_only_ips} // [] };
            dbg("  include_only_ips: " . scalar(@incl_only_ips) . " entries");
        }
    } else {
        dbg("Server config not available, using local INI settings");
    }
} else {
    dbg("No GLPI URL configured, using local INI settings only");
}

# ---------------------------------------------------------------------------
# Step 1 — HTTP.SYS port detection (Windows only)
# ---------------------------------------------------------------------------
my %httpsys_ports;
if ($OSNAME eq 'MSWin32') {
    info("Detecting HTTP.SYS ports...");
    my @lines = eval { _cmd('netsh http show servicestate view=requestq') };
    for my $line (@lines) {
        if ($line =~ m{https?://[^:]*:(\d+)}i) {
            $httpsys_ports{int($1)} = 1;
        }
    }
    my $count = scalar keys %httpsys_ports;
    dbg("HTTP.SYS ports: " . ($count ? join(', ', sort { $a <=> $b } keys %httpsys_ports) : 'none'));
}

# ---------------------------------------------------------------------------
# Step 2 — Process list (WMIC, with PowerShell fallback)
# ---------------------------------------------------------------------------
info("Collecting process list...");
my %proc;   # pid => { name, description, cmd, session_id, mem_kb }

if ($OSNAME eq 'MSWin32') {
    _collectProcessesWindows();
} else {
    _collectProcessesLinux();
}

# Inject synthetic HTTP.SYS process
$proc{5} = {
    pid         => 5,
    name        => 'HTTP.SYS',
    description => 'Windows HTTP Service (kernel-mode)',
    cmd         => 'http.sys',
    session_id  => 0,
    mem_kb      => 0,
};

dbg("Got " . scalar(keys %proc) . " processes (incl. HTTP.SYS synthetic)");

# ---------------------------------------------------------------------------
# Step 3 — Windows service lookup by PID
# ---------------------------------------------------------------------------
my %svc_by_pid;   # pid => service_name
if ($OSNAME eq 'MSWin32') {
    info("Collecting Windows services...");
    my @lines = _cmd('wmic service where "State=\'Running\'" get ProcessId,Name /format:csv');
    my @headers;
    for my $line (@lines) {
        $line =~ s/\r|\n//g;
        next unless $line =~ /\S/;
        if (!@headers) { @headers = split /,/, $line; next; }
        my @f = split /,/, $line, scalar(@headers);
        my %r; @r{@headers} = @f;
        my $pid = $r{ProcessId} // '';
        next unless $pid =~ /^\d+$/ && int($pid) > 0;
        $svc_by_pid{int($pid)} = $r{Name} // '';
    }
    dbg("Got " . scalar(keys %svc_by_pid) . " running services");
}

# ---------------------------------------------------------------------------
# Step 4 — Network connections via PowerShell (TCP + UDP)
# ---------------------------------------------------------------------------
info("Collecting network connections (PowerShell)...");
my @connections;

if ($OSNAME eq 'MSWin32') {
    _collectConnectionsWindows_PS();
    if (!@connections) {
        info("PowerShell failed, falling back to netstat -ano");
        _collectConnectionsWindows_Netstat();
    }
} else {
    _collectConnectionsLinux();
}

dbg("Got " . scalar(@connections) . " raw connections");

# ---------------------------------------------------------------------------
# Step 5 — Filter, enrich, normalize
# ---------------------------------------------------------------------------
info("Filtering and enriching connections...");
my @excl_proc_re = map { qr/^\Q$_\E$/i } @excl_procs;

# Build set of ports this machine is listening on (for inbound detection)
my %local_listen_ports;
for my $c (@connections) {
    my $st = $c->{state} // '';
    if ($st eq 'Listen' || $st eq 'LISTENING' || $st eq 'LISTEN') {
        $local_listen_ports{$c->{local_port}} = 1;
    }
}
dbg("Listening ports for inbound detection: " . join(', ', sort { $a <=> $b } keys %local_listen_ports));

my @filtered;
my $established_only = $config{established_only};
my $skip_ipv6        = $config{skip_ipv6};
my $skip_loopback    = $config{skip_loopback};
my $ephemeral        = $config{ephemeral_port_threshold};

for my $c (@connections) {
    my $ra    = $c->{remote_addr} // '';
    my $la    = $c->{local_addr}  // '';
    my $pid   = $c->{pid}         // 0;
    my $pname = $proc{$pid}{name} // '';
    my $state = $c->{state}       // '';
    my $rport = $c->{remote_port} // 0;
    my $lport = $c->{local_port}  // 0;

    # ── Filters from INI ──

    # ESTABLISHED only (skip Listen, TimeWait, etc.)
    if ($established_only && $c->{protocol} eq 'TCP') {
        next unless $state eq 'Established' || $state eq 'ESTABLISHED';
    }

    # Skip IPv6 (contains ':' in addr, but not IPv4-mapped ::ffff:x.x.x.x)
    if ($skip_ipv6) {
        next if $ra =~ /:/ && $ra !~ /^::ffff:\d+\.\d+\.\d+\.\d+$/;
        next if $la =~ /:/ && $la !~ /^::ffff:\d+\.\d+\.\d+\.\d+$/;
    }

    # Skip loopback
    if ($skip_loopback) {
        next if $ra =~ /^127\./ || $ra eq '::1';
        next if $la =~ /^127\./ || $la eq '::1';
    }

    # Skip wildcard/empty remote
    next if $ra eq '' || $ra eq '0.0.0.0' || $ra eq '::' || $ra eq '*';

    # ── Determine direction ──
    # Inbound: local_port is a service we're listening on, remote_port is ephemeral
    # Outbound: remote_port is a service, local_port is ephemeral
    my $conn_direction = 'outbound';
    if ($local_listen_ports{$lport} && $ephemeral > 0 && $rport >= $ephemeral) {
        $conn_direction = 'inbound';
    }

    # Skip ephemeral remote ports — BUT keep if it's inbound (remote is the client)
    if ($conn_direction eq 'outbound') {
        next if $ephemeral > 0 && $rport >= $ephemeral;
    }

    # Process exclusion
    if (@excl_proc_re) {
        my $skip = 0;
        for my $re (@excl_proc_re) {
            if ($pname =~ $re) { $skip = 1; last; }
        }
        next if $skip;
    }

    # Remote IP exclusion
    if (@excl_remote_ips) {
        my $skip = 0;
        for my $eip (@excl_remote_ips) { if ($ra eq $eip) { $skip = 1; last; } }
        next if $skip;
    }

    # Remote port exclusion
    if (@excl_remote_ports) {
        my $skip = 0;
        for my $ep (@excl_remote_ports) { if ($rport == $ep) { $skip = 1; last; } }
        next if $skip;
    }

    # Include-only IPs (CIDR not implemented yet, exact match)
    if (@incl_only_ips) {
        my $ok = 0;
        for my $ip (@incl_only_ips) { if ($ra eq $ip) { $ok = 1; last; } }
        next unless $ok;
    }

    # ── Normalize IPv4-mapped IPv6 ──
    $ra =~ s/^::ffff://i;
    $la =~ s/^::ffff://i;

    # ── Enrich ──
    $c->{remote_addr}     = $ra;
    $c->{local_addr}      = $la;
    $c->{process_name}    = $pname;
    $c->{service_name}    = $svc_by_pid{$pid} // '';
    $c->{description}     = $proc{$pid}{description} // '';
    $c->{session_id}      = $proc{$pid}{session_id}  // 0;
    $c->{cmd}             = $proc{$pid}{cmd}          // '';
    $c->{conn_direction}  = $conn_direction;
    # For inbound, the "service port" is local_port
    $c->{service_port}    = ($conn_direction eq 'inbound') ? $lport : $rport;

    push @filtered, $c;
}

dbg("After filtering: " . scalar(@filtered) . " connections");

# ---------------------------------------------------------------------------
# Step 6 — DNS resolution
# ---------------------------------------------------------------------------
info("Resolving remote hostnames...");
my %dns_cache;
my $resolved_count = 0;
for my $c (@filtered) {
    my $ip = $c->{remote_addr};
    next unless $ip && $ip =~ /^\d+\.\d+\.\d+\.\d+$/;
    unless (exists $dns_cache{$ip}) {
        my $packed = inet_aton($ip);
        my $name   = $packed ? gethostbyaddr($packed, AF_INET) : undef;
        $dns_cache{$ip} = $name // $ip;
        $resolved_count++ if $name;
    }
    $c->{remote_hostname} = $dns_cache{$ip};
}
dbg("Resolved $resolved_count unique IPs via DNS");

# ---------------------------------------------------------------------------
# Step 7 — Build listening ports list
# ---------------------------------------------------------------------------
my @listening;
for my $c (@connections) {
    my $state = $c->{state} // '';
    next unless $state eq 'Listen' || $state eq 'LISTENING' || $state eq 'LISTEN'
                || $c->{protocol} eq 'UDP';

    my $pid   = $c->{pid} // 0;
    push @listening, {
        protocol     => $c->{protocol},
        local_addr   => $c->{local_addr},
        local_port   => $c->{local_port},
        pid          => $pid,
        process_name => $proc{$pid}{name} // '',
        service_name => $svc_by_pid{$pid} // '',
        created_at   => $c->{created_at}  // '',
    };
}
dbg("Listening ports: " . scalar(@listening));

# ---------------------------------------------------------------------------
# Step 8 — Build process list for cache
# ---------------------------------------------------------------------------
my @proc_list;
for my $p (sort { ($a->{pid} // 0) <=> ($b->{pid} // 0) } values %proc) {
    push @proc_list, {
        pid         => $p->{pid},
        name        => $p->{name},
        description => $p->{description} // '',
        cmd         => $p->{cmd}         // '',
        session_id  => $p->{session_id}  // 0,
        mem_kb      => $p->{mem_kb}      // 0,
    };
}

# ---------------------------------------------------------------------------
# Step 9 — Write JSON cache
# ---------------------------------------------------------------------------
my $elapsed = time() - $start;

my $data = {
    schema_version => 3,
    collector_version => $VERSION,
    collection_method => (@connections && $connections[0]{created_at})
                         ? 'powershell' : 'netstat',
    collected_at   => strftime('%Y-%m-%d %H:%M:%S', gmtime()),
    hostname       => $hostname,
    stats          => {
        connections     => scalar(@filtered),
        listening       => scalar(@listening),
        processes       => scalar(@proc_list),
        httpsys_ports   => scalar(keys %httpsys_ports),
        raw_connections => scalar(@connections),
        elapsed_seconds => $elapsed,
    },
    connections => \@filtered,
    listening   => \@listening,
    processes   => \@proc_list,
    httpsys_ports => [ sort { $a <=> $b } keys %httpsys_ports ],
};

my $json = JSON::PP->new->utf8->pretty->canonical->encode($data);
my $cache_file = File::Spec->catfile($vardir, 'netstat-cache.json');

mkdir $vardir unless -d $vardir;
open(my $fh, '>', $cache_file) or die "Cannot write $cache_file: $!\n";
print $fh $json;
close $fh;

info("Cache written to: $cache_file");
info("Done in ${elapsed}s - ${\scalar @filtered} connections, "
    . "${\scalar @listening} listening, ${\scalar @proc_list} processes"
    . ($data->{collection_method} eq 'powershell' ? ' [PowerShell+CreationTime]' : ' [netstat fallback]'));

# ---------------------------------------------------------------------------
# Step 10 — Push to GLPI REST API (if enabled)
# ---------------------------------------------------------------------------
if ($config{push_enabled} && $config{glpi_url}) {
    info("Pushing to GLPI REST API...");
    _pushToGLPI($data);
}

exit 0;

# ===========================================================================
# Collection subroutines
# ===========================================================================

sub _collectConnectionsWindows_PS {
    # ── TCP via Get-NetTCPConnection ──────────────────────────────────
    my $ps_tcp = q{powershell -NoProfile -NonInteractive -Command }
        . q{"Get-NetTCPConnection }
        . q{| Select-Object LocalAddress,LocalPort,RemoteAddress,RemotePort,}
        . q{State,OwningProcess,CreationTime,OffloadState,AppliedSetting }
        . q{| ConvertTo-Csv -NoTypeInformation"};

    my @tcp_lines = _cmd($ps_tcp);

    my @headers;
    for my $line (@tcp_lines) {
        $line =~ s/\r|\n//g;
        next unless $line =~ /\S/;
        $line =~ s/^"//; $line =~ s/"$//;

        if (!@headers) { @headers = split /","/, $line; next; }

        my @fields = split /","/, $line;
        my %rec;
        @rec{@headers} = @fields;

        my $pid   = int($rec{OwningProcess} // 0);
        my $lport = int($rec{LocalPort}     // 0);

        # HTTP.SYS remap
        if ($pid == 4 && $httpsys_ports{$lport}) {
            $pid = 5;
        }

        push @connections, {
            protocol        => 'TCP',
            local_addr      => $rec{LocalAddress}   // '',
            local_port      => $lport,
            remote_addr     => $rec{RemoteAddress}   // '',
            remote_port     => int($rec{RemotePort}  // 0),
            state           => $rec{State}           // '',
            pid             => $pid,
            created_at      => $rec{CreationTime}    // '',
            offload_state   => $rec{OffloadState}    // '',
            applied_setting => $rec{AppliedSetting}  // '',
        };
    }

    # ── UDP via Get-NetUDPEndpoint ────────────────────────────────────
    my $ps_udp = q{powershell -NoProfile -NonInteractive -Command }
        . q{"Get-NetUDPEndpoint }
        . q{| Select-Object LocalAddress,LocalPort,OwningProcess,CreationTime }
        . q{| ConvertTo-Csv -NoTypeInformation"};

    my @udp_lines = _cmd($ps_udp);

    my @udp_headers;
    for my $line (@udp_lines) {
        $line =~ s/\r|\n//g;
        next unless $line =~ /\S/;
        $line =~ s/^"//; $line =~ s/"$//;

        if (!@udp_headers) { @udp_headers = split /","/, $line; next; }

        my @fields = split /","/, $line;
        my %rec;
        @rec{@udp_headers} = @fields;

        push @connections, {
            protocol        => 'UDP',
            local_addr      => $rec{LocalAddress}   // '',
            local_port      => int($rec{LocalPort}  // 0),
            remote_addr     => '*',
            remote_port     => 0,
            state           => 'STATELESS',
            pid             => int($rec{OwningProcess} // 0),
            created_at      => $rec{CreationTime}   // '',
            offload_state   => '',
            applied_setting => '',
        };
    }
}

sub _collectConnectionsWindows_Netstat {
    my @lines = _cmd('netstat -ano');
    for my $line (@lines) {
        next unless $line =~ /^\s*(TCP|UDP)\s+(\S+)\s+(\S+)\s*(\S*)\s+(\d+)\s*$/i;
        my ($proto, $local, $remote, $state, $pid) = ($1, $2, $3, $4, $5);
        my ($la, $lp) = _splitAddrPort($local);
        my ($ra, $rp) = _splitAddrPort($remote);

        my $ipid = int($pid);
        if ($ipid == 4 && $httpsys_ports{$lp}) { $ipid = 5; }

        push @connections, {
            protocol        => uc($proto),
            local_addr      => $la,
            local_port      => $lp,
            remote_addr     => $ra,
            remote_port     => $rp,
            state           => $state || 'STATELESS',
            pid             => $ipid,
            created_at      => '',
            offload_state   => '',
            applied_setting => '',
        };
    }
}

sub _collectConnectionsLinux {
    my $use_ss  = -x '/usr/sbin/ss' || -x '/bin/ss';
    my $command = $use_ss ? 'ss -tunap --no-header' : 'netstat -tunap --numeric';
    my @lines   = _cmd($command);

    for my $line (@lines) {
        if ($use_ss) {
            next unless $line =~ /^(\S+)\s+\d+\s+\d+\s+(\S+)\s+(\S+)\s*(.*)/;
            my ($state, $local, $remote, $rest) = ($1, $2, $3, $4);
            my ($la, $lp) = _splitAddrPort($local);
            my ($ra, $rp) = _splitAddrPort($remote);
            my $pid = 0;
            $pid = int($1) if $rest =~ /pid=(\d+)/;
            my $proto = ($line =~ /^udp/i) ? 'UDP' : 'TCP';
            push @connections, {
                protocol => $proto, local_addr => $la, local_port => $lp,
                remote_addr => $ra, remote_port => $rp, state => uc($state),
                pid => $pid, created_at => '', offload_state => '',
                applied_setting => '',
            };
        } else {
            next unless $line =~ /^(tcp6?|udp6?)\s+\d+\s+\d+\s+(\S+)\s+(\S+)\s+(\S*)\s*(\S*)/i;
            my ($proto, $local, $remote, $state, $pp) = ($1, $2, $3, $4, $5);
            my ($la, $lp) = _splitAddrPort($local);
            my ($ra, $rp) = _splitAddrPort($remote);
            my $pid = 0;
            $pid = int($1) if ($pp // '') =~ /^(\d+)/;
            push @connections, {
                protocol => ($proto =~ /udp/i) ? 'UDP' : 'TCP',
                local_addr => $la, local_port => $lp,
                remote_addr => $ra, remote_port => $rp,
                state => uc($state || 'STATELESS'),
                pid => $pid, created_at => '', offload_state => '',
                applied_setting => '',
            };
        }
    }
}

sub _collectProcessesWindows {
    my @lines = _cmd('wmic process get ProcessId,Name,Description,CommandLine,SessionId,WorkingSetSize /format:csv');
    my $header_seen = 0;
    for my $line (@lines) {
        $line =~ s/\r|\n//g;
        next unless $line =~ /\S/;

        # Skip header row:
        # Node,CommandLine,Description,Name,ProcessId,SessionId,WorkingSetSize
        if (!$header_seen) {
            $header_seen = 1;
            next;
        }

        # Split on ALL commas — unlimited
        my @parts = split /,/, $line, -1;

        # Need at least 7 parts: Node + CommandLine(1+) + Desc + Name + PID + SID + WSS
        next unless @parts >= 7;

        # ---- Pop from the RIGHT (these fields NEVER contain commas) ----
        my $wss_raw        = pop @parts;   # WorkingSetSize  (always numeric)
        my $session_id_raw = pop @parts;   # SessionId       (always numeric)
        my $pid_raw        = pop @parts;   # ProcessId       (always numeric)
        my $name           = pop @parts;   # Name            (exe name, no commas)
        my $description    = pop @parts;   # Description     (almost never has commas)

        # ---- Shift from the LEFT ----
        my $node = shift @parts;           # Node (hostname, no commas)

        # ---- Everything remaining IS the CommandLine (commas intact) ----
        my $cmd = join(',', @parts);

        # Validate PID — skip garbage rows
        next unless defined $pid_raw && $pid_raw =~ /^\d+$/;
        my $pid = int($pid_raw);
        next unless $pid > 0;

        # Safe numeric conversions — no more "isn't numeric" warnings
        my $mem_kb     = ($wss_raw        // '') =~ /^\d+$/ ? int($wss_raw / 1024) : 0;
        my $session_id = ($session_id_raw // '') =~ /^\d+$/ ? int($session_id_raw)  : 0;

        $proc{$pid} = {
            name        => $name        // 'unknown',
            description => $description // '',
            cmd         => $cmd,
            session_id  => $session_id,
            mem_kb      => $mem_kb,
        };
    }
}
sub _collectProcessesLinux {
    my @lines = _cmd('ps -eo pid,user,rss,comm,args --no-headers');
    for my $line (@lines) {
        next unless $line =~ /^\s*(\d+)\s+(\S+)\s+(\d+)\s+(\S+)\s+(.*)/;
        $proc{int($1)} = {
            pid => int($1), name => $4, description => '', cmd => $5,
            session_id => 0, mem_kb => int($3), user => $2,
        };
    }
}

# ===========================================================================
# GLPI REST API push
# ===========================================================================
sub _pushToGLPI {
    my ($data) = @_;

    my $base = $config{glpi_url};
    $base =~ s{/+$}{};
    my $app_token  = $config{app_token};
    my $user_token = $config{user_token};
    my $push_mode  = lc($config{push_mode} // 'bulk');

    if ($push_mode eq 'bulk') {
        info("Push mode: bulk (lifecycle merge via push.php)");
        if ($OSNAME eq 'MSWin32') {
            _pushViaBulkPS($data, $base, $app_token, $user_token);
        } else {
            _pushViaBulkCurl($data, $base, $app_token, $user_token);
        }
    } else {
        info("Push mode: rest (legacy delete+insert via REST API)");
        if ($OSNAME eq 'MSWin32') {
            _pushViaPS($data, $base, $app_token, $user_token);
        } else {
            _pushViaCurl($data, $base, $app_token, $user_token);
        }
    }
}

# ===========================================================================
# Bulk push — single POST to push.php (v1.3 lifecycle merge)
# ===========================================================================

sub _pushViaBulkPS {
    my ($data, $base, $app_token, $user_token) = @_;

    my $tmp_json = File::Spec->catfile($vardir, 'netstat-push-payload.json');
    open(my $jfh, '>', $tmp_json) or do { err("Cannot write $tmp_json: $!"); return; };
    print $jfh encode_json($data);
    close $jfh;

    my $tmp_ps1 = File::Spec->catfile($vardir, 'netstat-bulk-push.ps1');
    open(my $pfh, '>', $tmp_ps1) or do { err("Cannot write $tmp_ps1: $!"); return; };
    print $pfh <<'PS1EOF';
param([string]$JsonPath, [string]$BaseUrl, [string]$AppToken, [string]$UserToken)

try {

$payload = Get-Content -Raw $JsonPath | ConvertFrom-Json

# Ignore SSL cert errors (self-signed)
try {
    if (-not ([System.Management.Automation.PSTypeName]'TrustAllCertsPolicy').Type) {
        $code = 'using System.Net;using System.Net.Security;using System.Security.Cryptography.X509Certificates;public class TrustAllCertsPolicy:ICertificatePolicy{public bool CheckValidationResult(ServicePoint sp,X509Certificate cert,WebRequest req,int problem){return true;}}'
        Add-Type -TypeDefinition $code
    }
    [System.Net.ServicePointManager]::CertificatePolicy = New-Object TrustAllCertsPolicy
} catch {
    Write-Host "BULK_WARN:SSL:$_"
}

[System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12

$headers = @{
    'Content-Type'  = 'application/json'
    'App-Token'     = $AppToken
    'Authorization' = "user_token $UserToken"
}

# Init session
$session = Invoke-RestMethod -Uri "$BaseUrl/apirest.php/initSession" -Headers $headers -Method Get
$token   = $session.session_token
if (-not $token) { Write-Host "BULK_ERROR:No_session_token"; exit 1 }

$headers.Remove('Authorization')
$headers['Session-Token'] = $token

Write-Host "BULK_SESSION:$token"

# POST entire payload to push.php using HttpWebRequest
# (Invoke-RestMethod has known issues with SSL + large POST on some .NET versions)
$pushUrl = "$BaseUrl/plugins/netstatconnections/front/push.php"

# Disable Expect: 100-continue globally
[System.Net.ServicePointManager]::Expect100Continue = $false

# Get payload size for logging
$bodyBytes = [System.IO.File]::ReadAllBytes($JsonPath)
Write-Host "BULK_PAYLOAD:size=$($bodyBytes.Length)bytes"

try {
    $webReq = [System.Net.HttpWebRequest]::Create($pushUrl)
    $webReq.Method = 'POST'
    $webReq.ContentType = 'application/json; charset=utf-8'
    $webReq.ContentLength = $bodyBytes.Length
    $webReq.Timeout = 300000          # 300 sec
    $webReq.ReadWriteTimeout = 300000
    $webReq.KeepAlive = $true
    $webReq.ServicePoint.Expect100Continue = $false
    $webReq.Headers.Add('App-Token', $AppToken)
    $webReq.Headers.Add('Session-Token', $token)

    # Accept any SSL cert (self-signed)
    $webReq.ServerCertificateValidationCallback = { $true }

    # Write body
    $reqStream = $webReq.GetRequestStream()
    $reqStream.Write($bodyBytes, 0, $bodyBytes.Length)
    $reqStream.Flush()
    $reqStream.Close()

    # Read response
    $webResp = $webReq.GetResponse()
    $respStream = $webResp.GetResponseStream()
    $reader = New-Object System.IO.StreamReader($respStream)
    $respText = $reader.ReadToEnd()
    $reader.Close()
    $respStream.Close()
    $httpCode = [int]$webResp.StatusCode
    $webResp.Close()

    Write-Host "BULK_HTTP:$httpCode"

    $resp = $respText | ConvertFrom-Json
    if ($resp.status -eq 'ok') {
        Write-Host "BULK_OK:pushed=$($resp.pushed),active=$($resp.stats.active),closed=$($resp.stats.closed),locked=$($resp.stats.locked),elapsed=$($resp.elapsed_ms)ms"
    } else {
        Write-Host "BULK_ERROR:status=$($resp.status),error=$($resp.error)"
    }
} catch [System.Net.WebException] {
    $ex = $_.Exception
    $httpCode = 0
    $respBody = ''
    try {
        if ($ex.Response) {
            $httpCode = [int]$ex.Response.StatusCode
            $errStream = $ex.Response.GetResponseStream()
            $errReader = New-Object System.IO.StreamReader($errStream)
            $respBody = $errReader.ReadToEnd()
            $errReader.Close()
            $errStream.Close()
            $ex.Response.Close()
        }
    } catch {}
    Write-Host "BULK_ERROR:HTTP=$httpCode msg=$($ex.Message) body=$respBody"
} catch {
    Write-Host "BULK_ERROR:HTTP=0 msg=$($_.Exception.Message) body="
}

# Kill session
try { Invoke-RestMethod -Uri "$BaseUrl/apirest.php/killSession" -Headers $headers -Method Get | Out-Null } catch {}

} catch {
    Write-Host "BULK_FATAL:$($_.Exception.Message)"
    exit 1
}
PS1EOF
    close $pfh;

    my $cmd = qq{powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass }
        . qq{-File "$tmp_ps1" }
        . qq{-JsonPath "$tmp_json" }
        . qq{-BaseUrl "$base" }
        . qq{-AppToken "$app_token" }
        . qq{-UserToken "$user_token"};

    my $output = `$cmd 2>&1`;
    chomp $output;

    for my $line (split /\n/, $output) {
        $line =~ s/\r//g;
        next unless $line =~ /\S/;
        if ($line =~ /^BULK_OK:(.+)/) {
            info("GLPI bulk push: $1");
        } elsif ($line =~ /^BULK_PAYLOAD:(.+)/) {
            info("GLPI bulk push: $1");
        } elsif ($line =~ /^BULK_HTTP:(\d+)/) {
            dbg("GLPI bulk push: HTTP $1");
        } elsif ($line =~ /^BULK_FATAL:(.+)/) {
            err("GLPI bulk push fatal: $1");
        } elsif ($line =~ /^BULK_ERROR:(.+)/) {
            err("GLPI bulk push error: $1");
        } elsif ($line =~ /^BULK_SESSION:/) {
            dbg("Session acquired");
        } elsif ($line =~ /^BULK_WARN:(.+)/) {
            dbg("GLPI bulk push warning: $1");
        } else {
            dbg("PS: $line");
        }
    }

    unless ($output =~ /BULK_OK:/) {
        err("GLPI bulk push: no BULK_OK line — push may have failed");
        err("Full output: $output") if $output;
    }

    unlink $tmp_json;
    unlink $tmp_ps1;
}

sub _pushViaBulkCurl {
    my ($data, $base, $app_token, $user_token) = @_;

    my $tmp_json = File::Spec->catfile($vardir, 'netstat-push-payload.json');
    open(my $jfh, '>', $tmp_json) or do { err("Cannot write $tmp_json: $!"); return; };
    print $jfh encode_json($data);
    close $jfh;

    # Init session
    my $init_cmd = "curl -sk -X GET '$base/apirest.php/initSession' "
        . "-H 'Content-Type: application/json' "
        . "-H 'App-Token: $app_token' "
        . "-H 'Authorization: user_token $user_token'";
    my $init_out = `$init_cmd 2>/dev/null`;
    chomp $init_out;

    my $init_data = eval { decode_json($init_out) };
    if (!$init_data || !$init_data->{session_token}) {
        err("GLPI bulk initSession failed: $init_out");
        unlink $tmp_json;
        return;
    }
    my $session = $init_data->{session_token};
    dbg("Session acquired: $session");

    # POST to push.php
    my $push_cmd = "curl -sk -X POST '$base/plugins/netstatconnections/front/push.php' "
        . "-H 'Content-Type: application/json' "
        . "-H 'App-Token: $app_token' "
        . "-H 'Session-Token: $session' "
        . "-d '\@$tmp_json'";
    my $push_out = `$push_cmd 2>/dev/null`;
    chomp $push_out;

    my $push_data = eval { decode_json($push_out) };
    if ($push_data && ($push_data->{status} // '') eq 'ok') {
        my $s = $push_data->{stats} // {};
        info(sprintf("GLPI bulk push: pushed=%d, active=%d, closed=%d, locked=%d, elapsed=%dms",
            $push_data->{pushed} // 0,
            $s->{active} // 0, $s->{closed} // 0, $s->{locked} // 0,
            $push_data->{elapsed_ms} // 0));
    } else {
        err("GLPI bulk push failed: $push_out");
    }

    # Kill session
    `curl -sk -X GET '$base/apirest.php/killSession' -H 'App-Token: $app_token' -H 'Session-Token: $session' 2>/dev/null`;

    unlink $tmp_json;
}

sub _pushViaPS {
    my ($data, $base, $app_token, $user_token) = @_;

    # Write payload JSON for PowerShell to read
    my $tmp_json = File::Spec->catfile($vardir, 'netstat-push-payload.json');
    open(my $jfh, '>', $tmp_json) or do { err("Cannot write $tmp_json: $!"); return; };
    print $jfh encode_json($data);
    close $jfh;

    # Write PS1 script to temp file (avoids Perl/PS variable interpolation issues)
    my $tmp_ps1 = File::Spec->catfile($vardir, 'netstat-push.ps1');
    open(my $pfh, '>', $tmp_ps1) or do { err("Cannot write $tmp_ps1: $!"); return; };
    print $pfh <<'PS1EOF';
param([string]$JsonPath, [string]$BaseUrl, [string]$AppToken, [string]$UserToken)

try {

$payload = Get-Content -Raw $JsonPath | ConvertFrom-Json

# Ignore SSL cert errors (self-signed)
try {
    if (-not ([System.Management.Automation.PSTypeName]'TrustAllCertsPolicy').Type) {
        $code = 'using System.Net;using System.Net.Security;using System.Security.Cryptography.X509Certificates;public class TrustAllCertsPolicy:ICertificatePolicy{public bool CheckValidationResult(ServicePoint sp,X509Certificate cert,WebRequest req,int problem){return true;}}'
        Add-Type -TypeDefinition $code
    }
    [System.Net.ServicePointManager]::CertificatePolicy = New-Object TrustAllCertsPolicy
} catch {
    Write-Host "PUSH_WARN:SSL:$_"
}

[System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12

$headers = @{
    'Content-Type'  = 'application/json'
    'App-Token'     = $AppToken
    'Authorization' = "user_token $UserToken"
}

# Init session
$session = Invoke-RestMethod -Uri "$BaseUrl/apirest.php/initSession" -Headers $headers -Method Get
$token   = $session.session_token
if (-not $token) { Write-Host "PUSH_ERROR:No_session_token"; exit 1 }

$headers.Remove('Authorization')
$headers['Session-Token'] = $token

# Resolve hostname to computers_id
$compId = 0
try {
    $search = Invoke-RestMethod -Uri "$BaseUrl/apirest.php/Computer?searchText[name]=$($payload.hostname)" `
        -Headers $headers -Method Get
    if ($search -and $search.Count -gt 0) {
        $compId = [int]$search[0].id
        Write-Host "PUSH_RESOLVED:$($payload.hostname)=$compId"
    }
} catch {
    Write-Host "PUSH_WARN:hostname_lookup:$($_.Exception.Message)"
}

if ($compId -eq 0) {
    Write-Host "PUSH_ERROR:Cannot_resolve_hostname_$($payload.hostname)_to_computer_id"
    try { Invoke-RestMethod -Uri "$BaseUrl/apirest.php/killSession" -Headers $headers -Method Get | Out-Null } catch {}
    exit 1
}

# Delete existing unlocked connections for this computer before pushing new ones
try {
    $existing = Invoke-RestMethod -Uri "$BaseUrl/apirest.php/PluginNetstatconnectionsConnection?searchText[computers_id]=$compId&range=0-999" `
        -Headers $headers -Method Get
    if ($existing) {
        $deleted = 0
        foreach ($row in $existing) {
            # Double-check: exact computers_id match (searchText does LIKE, could match 421 for 42)
            if ([int]$row.computers_id -ne $compId) { continue }
            $locked = 0; if ($row.is_locked) { $locked = [int]$row.is_locked }
            if ($locked -eq 0) {
                try {
                    Invoke-RestMethod -Uri "$BaseUrl/apirest.php/PluginNetstatconnectionsConnection/$($row.id)?force_purge=1" `
                        -Headers $headers -Method Delete | Out-Null
                    $deleted++
                } catch {}
            }
        }
        Write-Host "PUSH_CLEANUP:deleted=$deleted,kept_locked=$($existing.Count - $deleted)"
    }
} catch {
    Write-Host "PUSH_WARN:cleanup:$($_.Exception.Message)"
}

# Push each connection
$count = 0
$errs  = 0
foreach ($c in $payload.connections) {
    $rh = ''; if ($c.remote_hostname) { $rh = [string]$c.remote_hostname }
    $pn = ''; if ($c.process_name)    { $pn = [string]$c.process_name }
    $sn = ''; if ($c.service_name)    { $sn = [string]$c.service_name }
    $ca = ''; if ($c.created_at) {
        try {
            $dt = [datetime]::Parse($c.created_at)
            $ca = $dt.ToString('yyyy-MM-dd HH:mm:ss')
        } catch { $ca = '' }
    }
    $cd = 'outbound'; if ($c.conn_direction) { $cd = [string]$c.conn_direction }
    $sp = 0; if ($c.service_port) { $sp = [int]$c.service_port }

    # Convert collected_at to MySQL format
    $collAt = [string]$payload.collected_at
    try {
        $collAt = ([datetime]::Parse($collAt)).ToString('yyyy-MM-dd HH:mm:ss')
    } catch {}

    $body = @{
        input = @{
            computers_id    = $compId
            protocol        = [string]$c.protocol
            local_addr      = [string]$c.local_addr
            local_port      = [int]$c.local_port
            remote_addr     = [string]$c.remote_addr
            remote_port     = [int]$c.remote_port
            remote_hostname = $rh
            process_name    = $pn
            service_name    = $sn
            state           = [string]$c.state
            created_at      = $ca
            collected_at    = $collAt
            conn_direction  = $cd
            service_port    = $sp
            collection_method = [string]$payload.collection_method
        }
    } | ConvertTo-Json -Depth 5 -Compress

    # Debug: show first item's body
    if ($count -eq 0 -and $errs -eq 0) {
        Write-Host "PUSH_DEBUG_BODY:$body"
    }

    try {
        Invoke-RestMethod -Uri "$BaseUrl/apirest.php/PluginNetstatconnectionsConnection" `
            -Headers $headers -Method Post -Body $body -ContentType 'application/json' | Out-Null
        $count++
    } catch {
        $errs++
        if ($errs -le 3) {
            $errMsg = $_.Exception.Message
            # Try to get response body for details
            $respBody = ''
            try {
                if ($_.Exception.Response) {
                    $stream = $_.Exception.Response.GetResponseStream()
                    $reader = New-Object System.IO.StreamReader($stream)
                    $respBody = $reader.ReadToEnd()
                    $reader.Close()
                    $stream.Close()
                }
            } catch {}
            if ($respBody) {
                Write-Host "PUSH_ITEM_ERR:$errMsg BODY:$respBody"
            } else {
                Write-Host "PUSH_ITEM_ERR:$errMsg"
            }
        }
    }
}

# Kill session
try { Invoke-RestMethod -Uri "$BaseUrl/apirest.php/killSession" -Headers $headers -Method Get | Out-Null } catch {}

Write-Host "PUSHED:$count/$($payload.connections.Count)"
if ($errs -gt 0) { Write-Host "PUSH_ERRORS:$errs" }

} catch {
    Write-Host "PUSH_FATAL:$($_.Exception.Message)"
    exit 1
}
PS1EOF
    close $pfh;

    # Execute the PS1 script with parameters
    my $cmd = qq{powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass }
        . qq{-File "$tmp_ps1" }
        . qq{-JsonPath "$tmp_json" }
        . qq{-BaseUrl "$base" }
        . qq{-AppToken "$app_token" }
        . qq{-UserToken "$user_token"};

    my $output = `$cmd 2>&1`;
    chomp $output;

    # Log all PS output lines for debugging
    for my $line (split /\n/, $output) {
        $line =~ s/\r//g;
        next unless $line =~ /\S/;
        if ($line =~ /PUSHED:(\d+)\/(\d+)/) {
            info("GLPI: pushed $1/$2 connections (via PowerShell)");
        } elsif ($line =~ /PUSH_FATAL:(.+)/) {
            err("GLPI push fatal: $1");
        } elsif ($line =~ /PUSH_ERROR:(.+)/) {
            err("GLPI push error: $1");
        } elsif ($line =~ /PUSH_ITEM_ERR:(.+)/) {
            err("GLPI item push: $1");
        } elsif ($line =~ /PUSH_ERRORS:(\d+)/) {
            err("GLPI: $1 items failed to push");
        } elsif ($line =~ /PUSH_WARN:(.+)/) {
            dbg("GLPI push warning: $1");
        } else {
            dbg("PS: $line");
        }
    }

    unless ($output =~ /PUSHED:/) {
        err("GLPI push: no PUSHED line in output — script may have crashed");
        err("Full output: $output") if $output;
    }

    unlink $tmp_json;
    unlink $tmp_ps1;
}

sub _pushViaCurl {
    my ($data, $base, $app_token, $user_token) = @_;

    # Init session
    my $init_cmd = "curl -sk -X GET '$base/apirest.php/initSession' "
        . "-H 'Content-Type: application/json' "
        . "-H 'App-Token: $app_token' "
        . "-H 'Authorization: user_token $user_token'";
    my $init_out = `$init_cmd 2>/dev/null`;
    my $session  = eval { decode_json($init_out) };
    my $token    = $session->{session_token} // '';
    unless ($token) {
        err("GLPI initSession failed: $init_out");
        return;
    }

    my $count = 0;
    for my $c (@{$data->{connections}}) {
        my $payload = encode_json({ input => {
            computers_id    => 0,
            hostname        => $data->{hostname},
            protocol        => $c->{protocol},
            local_addr      => $c->{local_addr},
            local_port      => $c->{local_port},
            remote_addr     => $c->{remote_addr},
            remote_port     => $c->{remote_port},
            remote_hostname => $c->{remote_hostname} // '',
            process_name    => $c->{process_name}    // '',
            service_name    => $c->{service_name}    // '',
            state           => $c->{state},
            created_at      => $c->{created_at}      // '',
            collected_at    => $data->{collected_at},
        }});
        $payload =~ s/'/'\\''/g;

        my $r = `curl -sk -X POST '$base/apirest.php/PluginNetstatconnectionsConnection' \
            -H 'Content-Type: application/json' \
            -H 'App-Token: $app_token' \
            -H 'Session-Token: $token' \
            -d '$payload' 2>/dev/null`;
        $count++ if $r =~ /"id"/;
    }

    # Kill session
    `curl -sk -X GET '$base/apirest.php/killSession' \
        -H 'App-Token: $app_token' \
        -H 'Session-Token: $token' 2>/dev/null`;

    info("GLPI: pushed $count/" . scalar(@{$data->{connections}}) . " connections (via curl)");
}

# ===========================================================================
# Helpers
# ===========================================================================
sub _cmd {
    my ($cmd) = @_;
    dbg("CMD: $cmd");
    my @output = `$cmd 2>&1`;
    return @output;
}

sub _splitAddrPort {
    my ($s) = @_;
    return ('', 0) unless defined $s;
    return ($1, int($2)) if $s =~ /^\[(.+)\]:(\d+)$/;
    if ($s =~ /^(.+):(\d+|\*)$/) {
        return ($1, $2 eq '*' ? 0 : int($2));
    }
    return ($s, 0);
}

sub _fetchServerConfig {
    my ($glpi_url, $host) = @_;

    eval { require LWP::UserAgent };
    if ($@) {
        dbg("LWP::UserAgent not available — cannot fetch server config");
        return undef;
    }

    # Build endpoint URL
    $glpi_url =~ s|/+$||;
    my $url = "$glpi_url/plugins/netstatconnections/front/agentconfig.php";
    $url .= "?hostname=" . ($host // '') if $host;

    dbg("Fetching server config from: $url");

    my $ua = LWP::UserAgent->new(
        timeout  => 10,
        ssl_opts => { verify_hostname => 0 },
        agent    => "glpi-netstat-collect/$VERSION",
    );

    my $resp = $ua->get($url);
    if ($resp->is_success) {
        my $json = eval { JSON::PP::decode_json($resp->decoded_content) };
        if ($@ || ref($json) ne 'HASH') {
            dbg("Invalid JSON from server config endpoint");
            return undef;
        }
        return $json;
    }

    dbg("Server config endpoint: " . $resp->status_line);
    return undef;
}

sub _loadINI {
    my ($file) = @_;
    open(my $fh, '<', $file) or return;
    my $section = '';
    while (my $line = <$fh>) {
        chomp $line;
        $line =~ s/#.*//;
        $line =~ s/^\s+|\s+$//g;
        next unless $line;

        if ($line =~ /^\[(.+)\]$/) {
            $section = lc($1);
            next;
        }

        if ($section eq 'collection') {
            if ($line =~ /^(\w+)\s*=\s*(.+)/) {
                my ($k, $v) = (lc($1), $2);
                $v =~ s/\s+$//;
                $config{$k} = ($v =~ /^(yes|true|1)$/i) ? 1
                             : ($v =~ /^(no|false|0)$/i) ? 0
                             : $v;
            }
        }
        elsif ($section eq 'api') {
            if ($line =~ /^(\w+)\s*=\s*(.+)/) {
                my ($k, $v) = (lc($1), $2);
                $v =~ s/\s+$//;
                $config{$k} = $v;
            }
        }
        elsif ($section eq 'exclude_processes') {
            push @excl_procs, $line;
        }
        elsif ($section eq 'exclude_remote_ips') {
            push @excl_remote_ips, $line;
        }
        elsif ($section eq 'exclude_remote_ports') {
            push @excl_remote_ports, int($line) if $line =~ /^\d+$/;
        }
        elsif ($section eq 'include_only_remote_ips') {
            push @incl_only_ips, $line;
        }
    }
    close $fh;
}

__END__

=head1 NAME

glpi-netstat-collect.pl - Network connection collector for GLPI Agent

=head1 VERSION

2.0.0 — PowerShell-first with CreationTime, HTTP.SYS detection, UDP endpoints

=head1 CHANGES FROM v1.x

  - Windows: Get-NetTCPConnection replaces netstat -ano (structured CSV, no regex)
  - Windows: Get-NetUDPEndpoint for UDP listeners with CreationTime
  - CreationTime field: when the connection was established
  - OffloadState + AppliedSetting fields from PowerShell
  - HTTP.SYS detection via netsh: PID 4 remapped to synthetic PID 5
  - IPv4-mapped IPv6 normalization (::ffff:10.1.2.3 → 10.1.2.3)
  - schema_version bumped to 3
  - Fallback to netstat -ano if PowerShell fails (Server 2008 R2)

=cut
