package GLPI::Agent::Tools::NetStat;

=head1 NAME

GLPI::Agent::Tools::NetStat - Network connection collection via PowerShell / ss

=head1 DESCRIPTION

Collects active TCP/UDP connections, listening ports, and running processes.

Windows: Uses PowerShell Get-NetTCPConnection + Get-NetUDPEndpoint (structured
objects with CreationTime, OffloadState). Falls back to netstat -ano on
Server 2008 R2 / PowerShell < 4.0.

Also detects HTTP.SYS-managed ports via netsh and remaps PID 4 (System)
connections to a synthetic "HTTP.SYS" process (PID 5) — same convention as
SquaredUp DataOnDemand MP.

Linux: Uses ss -tunap, falls back to netstat -tunap.

=head1 VERSION

2.0.0 — PowerShell-first rewrite

=cut

use strict;
use warnings;
use English qw(-no_match_vars);
use Socket qw(inet_aton AF_INET);
use base 'Exporter';

use GLPI::Agent::Tools qw(
    getAllLines
    canRun
);

our $VERSION = '2.0.0';

our @EXPORT_OK = qw(
    getNetConnections
    getListeningPorts
    getProcessList
    getProcessMap
    resolveDNS
    clear_dns_cache
    getHttpSysPorts
);

our %EXPORT_TAGS = (
    all => \@EXPORT_OK,
);

my %_dns_cache;

# ---------------------------------------------------------------------------
# getNetConnections( logger => $logger )
#
# Returns list of hashrefs:
#   protocol, local_addr, local_port, remote_addr, remote_port,
#   state, pid, created_at, offload_state, applied_setting
# ---------------------------------------------------------------------------
sub getNetConnections {
    my (%params) = @_;
    my $logger = $params{logger};

    my @connections;

    if ($OSNAME eq 'MSWin32') {
        @connections = _getConnectionsWindows_PS(%params);
        if (!@connections) {
            $logger->info("NetStat: PowerShell collection failed, falling back to netstat -ano")
                if $logger;
            @connections = _getConnectionsWindows_Netstat(%params);
        }
    } else {
        @connections = _getConnectionsLinux(%params);
    }

    $logger->debug2("NetStat: collected " . scalar(@connections) . " connections")
        if $logger;

    return @connections;
}

# ---------------------------------------------------------------------------
# Windows — PowerShell Get-NetTCPConnection + Get-NetUDPEndpoint
# ---------------------------------------------------------------------------
sub _getConnectionsWindows_PS {
    my (%params) = @_;
    my $logger = $params{logger};

    my @connections;

    # ── HTTP.SYS detection ─────────────────────────────────────────────
    # Ports managed by http.sys show PID 4 (System) in netstat/PowerShell.
    # We remap those to synthetic PID 5 / process "HTTP.SYS" (like SquaredUp).
    my %httpsys_ports = map { $_ => 1 } getHttpSysPorts(logger => $logger);
    my $httpsys_count = scalar keys %httpsys_ports;
    $logger->debug2("NetStat: detected $httpsys_count HTTP.SYS-managed ports")
        if $logger && $httpsys_count;

    # ── TCP connections ────────────────────────────────────────────────
    my $ps_tcp = q{powershell -NoProfile -NonInteractive -Command }
        . q{"Get-NetTCPConnection }
        . q{| Select-Object LocalAddress,LocalPort,RemoteAddress,RemotePort,}
        . q{State,OwningProcess,CreationTime,OffloadState,AppliedSetting }
        . q{| ConvertTo-Csv -NoTypeInformation"};

    my @tcp_lines = getAllLines(command => $ps_tcp, logger => $logger);

    my @tcp_headers;
    for my $line (@tcp_lines) {
        $line =~ s/\r|\n//g;
        next unless $line =~ /\S/;

        # Strip surrounding quotes from CSV
        $line =~ s/^"//; $line =~ s/"$//;

        if (!@tcp_headers) {
            @tcp_headers = split /","/, $line;
            next;
        }

        my @fields = split /","/, $line;
        my %rec;
        @rec{@tcp_headers} = @fields;

        my $pid   = int($rec{OwningProcess} // 0);
        my $lport = int($rec{LocalPort}     // 0);

        # HTTP.SYS remap: if PID is 4 (System) and port is HTTP.SYS-managed
        if ($pid == 4 && $httpsys_ports{$lport}) {
            $pid = 5;  # synthetic PID — never collides (real PIDs are multiples of 4)
        }

        push @connections, {
            protocol        => 'TCP',
            local_addr      => $rec{LocalAddress}    // '',
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

    # ── UDP endpoints ──────────────────────────────────────────────────
    my $ps_udp = q{powershell -NoProfile -NonInteractive -Command }
        . q{"Get-NetUDPEndpoint }
        . q{| Select-Object LocalAddress,LocalPort,OwningProcess,CreationTime }
        . q{| ConvertTo-Csv -NoTypeInformation"};

    my @udp_lines = getAllLines(command => $ps_udp, logger => $logger);

    my @udp_headers;
    for my $line (@udp_lines) {
        $line =~ s/\r|\n//g;
        next unless $line =~ /\S/;

        $line =~ s/^"//; $line =~ s/"$//;

        if (!@udp_headers) {
            @udp_headers = split /","/, $line;
            next;
        }

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

    return @connections;
}

# ---------------------------------------------------------------------------
# Windows fallback — netstat -ano (Server 2008 R2 / PS < 4)
# ---------------------------------------------------------------------------
sub _getConnectionsWindows_Netstat {
    my (%params) = @_;
    my $logger = $params{logger};
    my @connections;

    my @lines = getAllLines(command => 'netstat -ano', logger => $logger);

    for my $line (@lines) {
        next unless $line =~ /^\s*(TCP|UDP)\s+(\S+)\s+(\S+)\s*(\S*)\s+(\d+)\s*$/i;

        my ($proto, $local, $remote, $state, $pid) = ($1, $2, $3, $4, $5);
        my ($la, $lp) = _splitAddrPort($local);
        my ($ra, $rp) = _splitAddrPort($remote);

        push @connections, {
            protocol        => uc($proto),
            local_addr      => $la,
            local_port      => $lp,
            remote_addr     => $ra,
            remote_port     => $rp,
            state           => $state || 'STATELESS',
            pid             => int($pid),
            created_at      => '',     # not available via netstat
            offload_state   => '',
            applied_setting => '',
        };
    }

    return @connections;
}

# ---------------------------------------------------------------------------
# Linux — ss -tunap, fallback netstat -tunap
# ---------------------------------------------------------------------------
sub _getConnectionsLinux {
    my (%params) = @_;
    my $logger = $params{logger};
    my @connections;

    my $use_ss  = canRun('ss');
    my $command  = $use_ss ? 'ss -tunap --no-header' : 'netstat -tunap --numeric';
    my @lines    = getAllLines(command => $command, logger => $logger);

    for my $line (@lines) {
        if ($use_ss) {
            # ss output: State Recv-Q Send-Q Local:Port  Peer:Port  Process
            next unless $line =~ /^(\S+)\s+\d+\s+\d+\s+(\S+)\s+(\S+)\s*(.*)/;
            my ($state, $local, $remote, $rest) = ($1, $2, $3, $4);
            my ($la, $lp) = _splitAddrPort($local);
            my ($ra, $rp) = _splitAddrPort($remote);

            my $pid = 0;
            if ($rest =~ /pid=(\d+)/) {
                $pid = int($1);
            }

            # ss states: ESTAB, LISTEN, TIME-WAIT, etc.
            my $proto = ($line =~ /^udp/i) ? 'UDP' : 'TCP';

            push @connections, {
                protocol        => $proto,
                local_addr      => $la,
                local_port      => $lp,
                remote_addr     => $ra,
                remote_port     => $rp,
                state           => uc($state),
                pid             => $pid,
                created_at      => '',
                offload_state   => '',
                applied_setting => '',
            };
        } else {
            # netstat: Proto Recv-Q Send-Q Local Foreign State PID/Program
            next unless $line =~ /^(tcp6?|udp6?)\s+\d+\s+\d+\s+(\S+)\s+(\S+)\s+(\S*)\s*(\S*)/i;
            my ($proto, $local, $remote, $state, $pidprog) = ($1, $2, $3, $4, $5);
            my ($la, $lp) = _splitAddrPort($local);
            my ($ra, $rp) = _splitAddrPort($remote);

            my $pid = 0;
            $pid = int($1) if ($pidprog // '') =~ /^(\d+)/;

            push @connections, {
                protocol        => ($proto =~ /udp/i) ? 'UDP' : 'TCP',
                local_addr      => $la,
                local_port      => $lp,
                remote_addr     => $ra,
                remote_port     => $rp,
                state           => uc($state || 'STATELESS'),
                pid             => $pid,
                created_at      => '',
                offload_state   => '',
                applied_setting => '',
            };
        }
    }

    return @connections;
}

# ---------------------------------------------------------------------------
# getHttpSysPorts( logger => $logger )
#
# Returns list of port numbers managed by HTTP.SYS (IIS, ADFS, WinRM, etc.)
# Uses: netsh http show servicestate view=requestq
# ---------------------------------------------------------------------------
sub getHttpSysPorts {
    my (%params) = @_;
    my $logger = $params{logger};
    my %ports;

    return () unless $OSNAME eq 'MSWin32';

    my @lines = eval { getAllLines(
        command => 'netsh http show servicestate view=requestq',
        logger  => $logger,
    ) };

    if ($@ || !@lines) {
        $logger->debug2("NetStat: netsh http query failed: $@") if $logger;
        return ();
    }

    for my $line (@lines) {
        # Lines like:   HTTP://+:80/  or  HTTPS://HOSTNAME:443/path
        if ($line =~ m{https?://[^:]*:(\d+)}i) {
            $ports{int($1)} = 1;
        }
        # Also catch "Registered URL: ..." format
        if ($line =~ m{URL:\s*https?://[^:]*:(\d+)}i) {
            $ports{int($1)} = 1;
        }
    }

    return keys %ports;
}

# ---------------------------------------------------------------------------
# getListeningPorts( logger => $logger )
# ---------------------------------------------------------------------------
sub getListeningPorts {
    my (%params) = @_;
    my @all = getNetConnections(%params);
    return grep {
           ($_->{state} // '') eq 'Listen'
        || ($_->{state} // '') eq 'LISTENING'
        || ($_->{state} // '') eq 'LISTEN'
        || ($_->{protocol} // '') eq 'UDP'
    } @all;
}

# ---------------------------------------------------------------------------
# getProcessList( logger => $logger )
# Returns list of hashrefs: pid, name, description, cmd, user, session_id, mem_kb
# ---------------------------------------------------------------------------
sub getProcessList {
    my (%params) = @_;
    my $logger = $params{logger};
    my @processes;

    if ($OSNAME eq 'MSWin32') {
        @processes = _getProcessListWindows(%params);
    } else {
        @processes = _getProcessListLinux(%params);
    }

    # Inject synthetic HTTP.SYS process (PID 5)
    push @processes, {
        pid         => 5,
        name        => 'HTTP.SYS',
        description => 'Windows HTTP Service (kernel-mode)',
        cmd         => 'http.sys',
        user        => 'SYSTEM',
        session_id  => 0,
        mem_kb      => 0,
    };

    $logger->debug2("NetStat: collected " . scalar(@processes) . " processes")
        if $logger;

    return @processes;
}

sub _getProcessListWindows {
    my (%params) = @_;
    my $logger = $params{logger};
    my @processes;

    # Try WMIC first (available on Server 2012-2022, Win10)
    if (canRun('wmic')) {
        $logger->debug("CMD: wmic process get ProcessId,Name,Description,CommandLine,SessionId,WorkingSetSize /format:csv")
            if $logger;

        my @lines = getAllLines(
            command => 'wmic process get ProcessId,Name,Description,CommandLine,SessionId,WorkingSetSize /format:csv',
            logger  => $logger,
        );

        # WMIC alphabetizes columns → actual header:
        #   Node,CommandLine,Description,Name,ProcessId,SessionId,WorkingSetSize
        #
        # CommandLine often contains commas (Chrome, Edge, Teams, Electron):
        #   --field-trial-handle=2040,262144,524288
        #
        # FIX: Pop clean numeric fields from the right, shift Node from left,
        #      reassemble CommandLine from whatever remains in the middle.

        my $header_seen = 0;
        for my $line (@lines) {
            $line =~ s/\r|\n//g;
            next unless $line =~ /\S/;

            # Skip header row
            if (!$header_seen) {
                $header_seen = 1;
                next;
            }

            # Split on ALL commas (no limit)
            my @parts = split /,/, $line, -1;

            # Minimum: Node + >=1 CmdLine part + Desc + Name + PID + SID + WSS = 7
            next unless @parts >= 7;

            # -- Right side (never contain commas) --
            my $wss_raw        = pop @parts;   # WorkingSetSize
            my $session_id_raw = pop @parts;   # SessionId
            my $pid_raw        = pop @parts;   # ProcessId
            my $name           = pop @parts;   # Name
            my $description    = pop @parts;   # Description

            # -- Left side --
            my $node = shift @parts;           # Node (hostname)

            # -- Middle = CommandLine with commas preserved --
            my $cmd = join(',', @parts);

            # Validate PID
            next unless defined $pid_raw && $pid_raw =~ /^\d+$/;
            my $pid = int($pid_raw);
            next unless $pid > 0;

            # Safe numeric conversions
            my $mem_kb     = ($wss_raw        // '') =~ /^\d+$/ ? int($wss_raw / 1024) : 0;
            my $session_id = ($session_id_raw // '') =~ /^\d+$/ ? int($session_id_raw)  : 0;

            push @processes, {
                pid         => $pid,
                name        => $name        // 'unknown',
                description => $description // '',
                cmd         => $cmd,
                user        => '',
                session_id  => $session_id,
                mem_kb      => $mem_kb,
                cpu_percent => 0,
            };
        }

    } else {
        # PowerShell fallback (Windows 11 / Server 2025+ where WMIC is removed)
        # PowerShell ConvertTo-Csv properly quotes fields — no comma issue
        my $ps_cmd = q{powershell -NoProfile -NonInteractive -Command }
            . q{"Get-Process | Select-Object Id,ProcessName,Description,Path,SessionId,WorkingSet64 }
            . q{| ConvertTo-Csv -NoTypeInformation"};

        my @lines = getAllLines(command => $ps_cmd, logger => $logger);
        my @headers;
        for my $line (@lines) {
            $line =~ s/\r|\n//g;
            next unless $line =~ /\S/;

            # PowerShell CSV is properly quoted: "field1","field2","field3"
            $line =~ s/^"//; $line =~ s/"$//;

            if (!@headers) {
                @headers = split /","/, $line;
                next;
            }

            my @fields = split /","/, $line;
            my %rec;
            @rec{@headers} = @fields;

            my $pid = int($rec{Id} // 0);
            next unless $pid > 0;

            my $ws = $rec{WorkingSet64} // 0;
            my $mem_kb = ($ws =~ /^\d+$/) ? int($ws / 1024) : 0;

            push @processes, {
                pid         => $pid,
                name        => $rec{ProcessName} // 'unknown',
                description => $rec{Description} // '',
                cmd         => $rec{Path}        // '',
                user        => '',
                session_id  => int($rec{SessionId} // 0),
                mem_kb      => $mem_kb,
                cpu_percent => 0,
            };
        }
    }

    $logger->debug2("NetStat: collected " . scalar(@processes) . " processes")
        if $logger;

    return @processes;
}

sub _getProcessListLinux {
    my (%params) = @_;
    my $logger = $params{logger};
    my @processes;

    # ps with wide output
    my @lines = getAllLines(
        command => 'ps -eo pid,user,rss,comm,args --no-headers',
        logger  => $logger,
    );

    for my $line (@lines) {
        next unless $line =~ /^\s*(\d+)\s+(\S+)\s+(\d+)\s+(\S+)\s+(.*)/;
        push @processes, {
            pid         => int($1),
            user        => $2,
            mem_kb      => int($3),
            name        => $4,
            description => '',
            cmd         => $5,
            session_id  => 0,
        };
    }

    return @processes;
}

# ---------------------------------------------------------------------------
# getProcessMap( logger => $logger )
# Returns hashref keyed by PID for O(1) lookup
# ---------------------------------------------------------------------------
sub getProcessMap {
    my (%params) = @_;
    my $logger = $params{logger};
    my %proc;

    if ($OSNAME eq 'MSWin32') {
        my @list = _getProcessListWindows(%params);
        for my $p (@list) {
            $proc{$p->{pid}} = {
                name        => $p->{name},
                description => $p->{description},
                cmd         => $p->{cmd},
                session_id  => $p->{session_id},
                mem_kb      => $p->{mem_kb},
            };
        }
    } else {
        # Linux: ps aux
        my @lines = getAllLines(command => 'ps aux --no-headers', logger => $logger);
        for my $line (@lines) {
            my ($user, $pid, undef, undef, undef, $rss, undef, undef, undef, undef, @cmd_parts)
                = split /\s+/, $line;
            next unless defined $pid && $pid =~ /^\d+$/;
            my $cmd_str = join(' ', @cmd_parts);
            my $name = (split m{/}, $cmd_parts[0] // '')[-1] // 'unknown';
            $proc{int($pid)} = {
                name        => $name,
                description => '',
                cmd         => $cmd_str,
                session_id  => 0,
                mem_kb      => int($rss // 0),
            };
        }
    }

    $logger->debug("Got " . scalar(keys %proc) . " processes (incl. HTTP.SYS synthetic)")
        if $logger;

    return %proc;
}

# ---------------------------------------------------------------------------
# resolveDNS( $ip )
# ---------------------------------------------------------------------------
sub resolveDNS {
    my ($ip) = @_;
    return $ip unless defined $ip && $ip =~ /^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/;
    return $_dns_cache{$ip} if exists $_dns_cache{$ip};

    my $packed = inet_aton($ip);
    my $hostname;
    if ($packed) {
        $hostname = gethostbyaddr($packed, AF_INET);
    }
    $_dns_cache{$ip} = $hostname // $ip;
    return $_dns_cache{$ip};
}

sub clear_dns_cache { %_dns_cache = () }

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
sub _splitAddrPort {
    my ($s) = @_;
    return ('', 0) unless defined $s;

    # IPv6: [::1]:443
    return ($1, int($2)) if $s =~ /^\[(.+)\]:(\d+)$/;

    # IPv4: 0.0.0.0:443 or *:*
    if ($s =~ /^(.+):(\d+|\*)$/) {
        return ($1, $2 eq '*' ? 0 : int($2));
    }

    return ($s, 0);
}

1;

__END__

=head1 EXPORTED FUNCTIONS

=head2 getNetConnections( logger => $logger )

Returns list of connection hashrefs with:
  protocol, local_addr, local_port, remote_addr, remote_port,
  state, pid, created_at, offload_state, applied_setting

=head2 getHttpSysPorts( logger => $logger )

Returns list of port numbers managed by HTTP.SYS kernel driver.

=head2 getListeningPorts( logger => $logger )

Filtered subset of getNetConnections: LISTEN + UDP only.

=head2 getProcessList( logger => $logger )

Returns list of process hashrefs:
  pid, name, description, cmd, user, session_id, mem_kb
Includes synthetic HTTP.SYS process (PID 5).

=head2 getProcessMap( logger => $logger )

Hash keyed by PID for O(1) lookup.

=head2 resolveDNS( $ip )

Reverse DNS with per-run memory cache.

=head1 VERSION

2.0.0

=cut
