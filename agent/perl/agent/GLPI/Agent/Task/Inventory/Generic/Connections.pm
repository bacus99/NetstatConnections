package GLPI::Agent::Task::Inventory::Generic::Connections;

=head1 NAME

GLPI::Agent::Task::Inventory::Generic::Connections

=head1 DESCRIPTION

Inventory module — runs as part of the standard Inventory task.
Collects TCP/UDP connections and pushes them to the NetstatConnections plugin.

Reads configuration from netstat-collect.ini (same file used by glpi-netstat-collect.pl).
Only one extra line is needed in [api]:

    push_token = <token from GLPI > Plugins > Network Connections>

The push URL is built automatically from the existing glpi_url entry.

=head1 CONFIGURATION

netstat-collect.ini (C:\Program Files\GLPI-Agent\netstat-collect.ini) :

    [api]
    glpi_url    = https://glpi.example.com/glpi
    push_token  = <token>          ; ← add this line
    push_enabled = yes

    [collection]
    established_only = yes
    skip_ipv6        = yes
    skip_loopback    = yes
    ephemeral_port_threshold = 49152

    [exclude_processes]
    svchost.exe
    ...

=cut

use strict;
use warnings;

use parent 'GLPI::Agent::Task::Inventory::Module';

use English    qw(-no_match_vars);
use HTTP::Request;
use LWP::UserAgent;
use Encode     qw(encode);
use Sys::Hostname;
use POSIX      qw(strftime);
use Socket     qw(inet_aton AF_INET);
use File::Spec;

use constant category => "network";

use GLPI::Agent::Tools::NetStat qw(
    getNetConnections
    getProcessMap
    getHttpSysPorts
);

# ── Module entry point ────────────────────────────────────────────────────────

sub isEnabled {
    my (%params) = @_;
    return 1;
}

sub doInventory {
    my (%params) = @_;

    my $logger = $params{logger};
    my $config = $params{config};

    # ── Load config from netstat-collect.ini ──────────────────────────────
    my $cfg = _readConfig($config, $logger);
    unless ($cfg && $cfg->{push_url} && $cfg->{push_token}) {
        $logger->debug('Connections: push_token not set in netstat-collect.ini [api] — skipping push')
            if $logger;
        return;
    }

    unless ($cfg->{push_enabled}) {
        $logger->debug('Connections: push_enabled = no in netstat-collect.ini — skipping')
            if $logger;
        return;
    }

    $logger->info('Connections: collecting') if $logger;

    # ── Collect ───────────────────────────────────────────────────────────
    my @raw = eval { getNetConnections(logger => $logger) };
    if ($@) {
        $logger->error("Connections: collection error — $@") if $logger;
        return;
    }

    my %proc_map = eval { getProcessMap(logger => $logger) };

    my %httpsys_ports;
    if ($OSNAME eq 'MSWin32') {
        %httpsys_ports = map { $_ => 1 } eval { getHttpSysPorts(logger => $logger) };
    }

    $logger->debug('Connections: raw=' . scalar(@raw)) if $logger;

    # ── Build local listen-port set (for direction detection) ─────────────
    my %listen_ports;
    for my $c (@raw) {
        my $st = uc($c->{state} // '');
        if ($st eq 'LISTEN' || $st eq 'LISTENING') {
            $listen_ports{ int($c->{local_port} // 0) } = 1;
        }
    }

    # ── Build exclusion sets from INI ─────────────────────────────────────
    my %excl_procs  = map { lc($_) => 1 } @{ $cfg->{exclude_processes}   // [] };
    my %excl_rips   = map { $_ => 1 }     @{ $cfg->{exclude_remote_ips}  // [] };
    my %excl_rports = map { $_ => 1 }     @{ $cfg->{exclude_remote_ports}// [] };
    my @incl_only   =                       @{ $cfg->{include_only_ips}   // [] };

    my $established_only = $cfg->{established_only} // 1;
    my $skip_ipv6        = $cfg->{skip_ipv6}        // 1;
    my $skip_loopback    = $cfg->{skip_loopback}     // 1;
    my $ephemeral        = $cfg->{ephemeral_port_threshold} // 49152;

    # ── Filter and enrich ─────────────────────────────────────────────────
    my %dns_cache;
    my @connections;

    for my $c (@raw) {
        my $ra    = $c->{remote_addr} // '';
        my $la    = $c->{local_addr}  // '';
        my $rport = int($c->{remote_port} // 0);
        my $lport = int($c->{local_port}  // 0);
        my $state = uc($c->{state} // '');
        my $pid   = int($c->{pid}   // 0);
        my $proto = uc($c->{protocol} // 'TCP');

        # ── Basic filters ──
        next if $state eq 'LISTEN' || $state eq 'LISTENING';
        next if $ra eq '' || $ra eq '*' || $ra eq '0.0.0.0' || $ra eq '::';
        next if $rport == 0;

        # Established only
        if ($established_only && $proto eq 'TCP') {
            next unless $state eq 'ESTABLISHED' || $state eq 'ESTAB'
                     || $state eq 'CLOSE_WAIT'  || $state eq 'TIME_WAIT'
                     || $state eq 'TIME-WAIT';
        }

        # IPv6
        if ($skip_ipv6) {
            next if $ra =~ /:/ && $ra !~ /^::ffff:\d+\.\d+\.\d+\.\d+$/;
            next if $la =~ /:/ && $la !~ /^::ffff:\d+\.\d+\.\d+\.\d+$/;
        }

        # Loopback
        if ($skip_loopback) {
            next if $ra =~ /^127\./ || $ra eq '::1';
            next if $la =~ /^127\./ || $la eq '::1';
        }

        # Normalize IPv4-mapped IPv6
        $ra =~ s/^::ffff://i;
        $la =~ s/^::ffff://i;

        # Determine direction
        my $conn_direction;
        if ($listen_ports{$lport} && $ephemeral > 0 && $rport >= $ephemeral) {
            $conn_direction = 'inbound';
        } else {
            $conn_direction = 'outbound';
        }

        # Skip ephemeral remote ports on outbound
        if ($conn_direction eq 'outbound' && $ephemeral > 0 && $rport >= $ephemeral) {
            next;
        }

        # ── INI exclusions ──
        my $pname = lc($pid > 0 && $proc_map{$pid} ? ($proc_map{$pid}{name} // '') : '');
        next if $excl_procs{$pname};
        next if $excl_rips{$ra};
        next if $excl_rports{$rport};

        if (@incl_only) {
            my $ok = 0;
            for my $ip (@incl_only) { if ($ra eq $ip) { $ok = 1; last } }
            next unless $ok;
        }

        # DNS (cached)
        my $remote_hostname = '';
        if ($ra =~ /^\d+\.\d+\.\d+\.\d+$/) {
            unless (exists $dns_cache{$ra}) {
                my $packed = inet_aton($ra);
                my $name   = $packed ? scalar gethostbyaddr($packed, AF_INET) : undef;
                $dns_cache{$ra} = $name // '';
            }
            $remote_hostname = $dns_cache{$ra};
        }

        push @connections, {
            protocol        => $proto,
            local_addr      => $la,
            local_port      => $lport,
            remote_addr     => $ra,
            remote_port     => $rport,
            remote_hostname => $remote_hostname,
            state           => $state,
            conn_direction  => $conn_direction,
            service_port    => ($conn_direction eq 'inbound') ? $lport : $rport,
            process_name    => $proc_map{$pid}{name} // '',
            service_name    => '',
            pid             => $pid,
            created_at      => $c->{created_at}      // '',
            offload_state   => $c->{offload_state}   // '',
            applied_setting => $c->{applied_setting} // '',
            collection_method => 'agent_perl',
        };
    }

    $logger->info('Connections: pushing ' . scalar(@connections) . ' connection(s)') if $logger;

    _push($cfg, {
        hostname          => _shortHostname(),
        collected_at      => strftime('%Y-%m-%d %H:%M:%S', localtime(time)),
        collection_method => 'agent_perl',
        connections       => \@connections,
    }, $logger);
}

# ── HTTP push ─────────────────────────────────────────────────────────────────

sub _push {
    my ($cfg, $payload, $logger) = @_;

    my $json = eval {
        require Cpanel::JSON::XS;
        Cpanel::JSON::XS->new->utf8->canonical->encode($payload);
    };
    if ($@) {
        require JSON::PP;
        $json = JSON::PP->new->utf8->canonical->encode($payload);
    }

    my $ua = LWP::UserAgent->new(
        timeout  => 60,
        ssl_opts => { verify_hostname => 0, SSL_verify_mode => 0 },
    );

    my $req = HTTP::Request->new(POST => $cfg->{push_url});
    $req->header('Content-Type'    => 'application/json; charset=utf-8');
    $req->header('X-NetStat-Token' => $cfg->{push_token});
    $req->content(encode('UTF-8', $json));

    $logger->debug("Connections: POST → $cfg->{push_url}") if $logger;

    my $resp = $ua->request($req);
    if ($resp->is_success) {
        $logger->info('Connections: push OK — ' . $resp->decoded_content) if $logger;
    } else {
        $logger->error('Connections: push FAILED ' . $resp->status_line
            . ' — ' . $resp->decoded_content) if $logger;
    }
}

# ── Config reader — parses netstat-collect.ini ────────────────────────────────

sub _readConfig {
    my ($agent_config, $logger) = @_;

    my $agent_root = $ENV{GLPI_AGENT_ROOT} // 'C:/Program Files/GLPI-Agent';

    my @paths = (
        File::Spec->catfile($agent_root, 'netstat-collect.ini'),
        'C:/Program Files/GLPI-Agent/netstat-collect.ini',
        'C:/Program Files (x86)/GLPI-Agent/netstat-collect.ini',
        '/etc/glpi-agent/netstat-collect.ini',
        '/usr/local/etc/glpi-agent/netstat-collect.ini',
    );

    for my $path (@paths) {
        next unless -f $path;
        $logger->debug("Connections: config from $path") if $logger;
        return _parseINI($path);
    }

    $logger->debug('Connections: netstat-collect.ini not found') if $logger;
    return undef;
}

sub _parseINI {
    my ($file) = @_;

    my %cfg = (
        push_enabled             => 0,
        established_only         => 1,
        skip_ipv6                => 1,
        skip_loopback            => 1,
        ephemeral_port_threshold => 49152,
        exclude_processes        => [],
        exclude_remote_ips       => [],
        exclude_remote_ports     => [],
        include_only_ips         => [],
    );

    open my $fh, '<', $file or return undef;
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

        if ($section eq 'collection' && $line =~ /^(\w+)\s*=\s*(.+)$/) {
            my ($k, $v) = (lc($1), $2);
            $v =~ s/\s+$//;
            $cfg{$k} = ($v =~ /^(yes|true|1)$/i)  ? 1
                     : ($v =~ /^(no|false|0)$/i)   ? 0
                     : $v;
        }
        elsif ($section eq 'api' && $line =~ /^(\w+)\s*=\s*(.+)$/) {
            my ($k, $v) = (lc($1), $2);
            $v =~ s/\s+$//;
            $cfg{$k} = ($v =~ /^(yes|true|1)$/i)  ? 1
                     : ($v =~ /^(no|false|0)$/i)   ? 0
                     : $v;
        }
        elsif ($section eq 'exclude_processes')      { push @{$cfg{exclude_processes}},   lc($line) }
        elsif ($section eq 'exclude_remote_ips')     { push @{$cfg{exclude_remote_ips}},      $line  }
        elsif ($section eq 'exclude_remote_ports')   { push @{$cfg{exclude_remote_ports}}, int($line) if $line =~ /^\d+$/ }
        elsif ($section eq 'include_only_remote_ips'){ push @{$cfg{include_only_ips}},        $line  }
    }
    close $fh;

    # Build push_url from glpi_url if not explicitly set
    if (!$cfg{push_url} && $cfg{glpi_url}) {
        (my $base = $cfg{glpi_url}) =~ s{/+$}{};
        $cfg{push_url} = "$base/plugins/netstatconnections/front/push.php";
    }

    return \%cfg;
}

sub _shortHostname {
    my $h = eval { Sys::Hostname::hostname() } // '';
    $h ||= do { my $o = `hostname 2>nul`; $o =~ s/\r?\n//g; $o };
    $h =~ s/\..*$//;
    return $h;
}

1;
