package GLPI::Agent::Task::NetStat;

=head1 NAME

GLPI::Agent::Task::NetStat - RESERVED library — do NOT deploy with Version.pm

=head1 WARNING

Do NOT install NetStat/Version.pm alongside this file on the GLPI Agent.

If Version.pm is present, the agent advertises "netstat" as an installed task
in its contact to the GlpiInventory server. GlpiInventory does not know that
task type and returns 400 Bad Request, which breaks ALL tasks including Inventory
and RemoteInventory.

Connection collection runs through:
  Task/Inventory/Generic/Connections.pm   (Inventory module — no new task type)

=head1 DESCRIPTION

Library code kept for reference. The actual push logic lives in Connections.pm.

=head1 CONFIGURATION

Create C:\Program Files\GLPI-Agent\etc\conf.d\netstat.cfg :

    push_url   = https://glpi.example.com/glpi/plugins/netstatconnections/front/push.php
    push_token = <token shown in GLPI > Plugins > Network Connections>

=cut

use strict;
use warnings;

use parent 'GLPI::Agent::Task';

use English    qw(-no_match_vars);
use HTTP::Request;
use LWP::UserAgent;
use Encode     qw(encode);
use Sys::Hostname;

use GLPI::Agent::Task::NetStat::Version;
use GLPI::Agent::Tools::NetStat qw(
    getNetConnections
    getProcessMap
);

our $VERSION = GLPI::Agent::Task::NetStat::Version::VERSION;

# ── Task entry points ─────────────────────────────────────────────────────────

sub isEnabled {
    my ($self, $contact) = @_;

    my $cfg = $self->_readConfig();
    unless ($cfg && $cfg->{push_url} && $cfg->{push_token}) {
        $self->{logger}->debug(
            'NetStat: disabled — missing push_url / push_token in conf.d/netstat.cfg'
        );
        return 0;
    }

    $self->{_cfg} = $cfg;
    $self->{logger}->debug("NetStat: enabled (push_url=$cfg->{push_url})");
    return 1;
}

sub run {
    my ($self, %params) = @_;

    my $logger = $self->{logger};
    my $cfg    = $self->{_cfg} // $self->_readConfig();

    unless ($cfg && $cfg->{push_url} && $cfg->{push_token}) {
        $logger->error('NetStat: missing configuration — aborting');
        return;
    }

    $logger->info('NetStat: collecting connections');

    # ── Collect ───────────────────────────────────────────────────────────
    my @raw = eval { getNetConnections(logger => $logger) };
    if ($@) {
        $logger->error("NetStat: collection error — $@");
        return;
    }

    my %proc_map = eval { getProcessMap(logger => $logger) };
    $logger->debug("NetStat: raw=" . scalar(@raw) . " connections collected");

    # ── Filter and enrich ─────────────────────────────────────────────────
    my @connections;
    for my $c (@raw) {
        # Skip unconnected / loopback / empty remote
        my $raddr = $c->{remote_addr} // '';
        my $rport = int($c->{remote_port} // 0);
        my $laddr = $c->{local_addr}  // '';

        next if $raddr eq '' || $raddr eq '*' || $raddr eq '0.0.0.0' || $raddr eq '::';
        next if $rport == 0;
        next if $laddr =~ /^127\./ || $laddr eq '::1';
        next if $raddr =~ /^127\./ || $raddr eq '::1';

        # Skip LISTENING entries (remote would be 0.0.0.0 — already filtered above)
        my $state = uc($c->{state} // '');
        next if $state eq 'LISTEN' || $state eq 'LISTENING';
        next if $state eq 'SYN_SENT' || $state eq 'SYN_RECEIVED';

        # Resolve process name from process map
        my $pid  = int($c->{pid} // 0);
        my $proc = ($pid > 0 && $proc_map{$pid}) ? $proc_map{$pid}{name} : '';

        # Determine connection direction
        my $lport = int($c->{local_port} // 0);
        my $conn_direction = _detectDirection($lport, $rport);

        push @connections, {
            protocol        => $c->{protocol}        // 'TCP',
            local_addr      => $laddr,
            local_port      => $lport,
            remote_addr     => $raddr,
            remote_port     => $rport,
            state           => $state,
            conn_direction  => $conn_direction,
            process_name    => $proc,
            pid             => $pid,
            created_at      => $c->{created_at}      // '',
            offload_state   => $c->{offload_state}   // '',
            applied_setting => $c->{applied_setting} // '',
        };
    }

    $logger->info('NetStat: pushing ' . scalar(@connections) . ' connection(s)');

    # ── Push ──────────────────────────────────────────────────────────────
    $self->_push($cfg, {
        hostname        => _shortHostname(),
        collected_at    => _now(),
        collection_method => 'agent_perl',
        connections     => \@connections,
    });
}

# ── Helpers ───────────────────────────────────────────────────────────────────

sub _detectDirection {
    my ($lport, $rport) = @_;

    # Remote port is a well-known service, our local is ephemeral → we are the client
    return 'outbound' if $rport  <  1024 && $lport >= 1024;

    # Our local port is a well-known service, remote is ephemeral → remote is the client
    return 'inbound'  if $lport  <  1024 && $rport >= 1024;

    # Remote uses an ephemeral port (≥49152) and our local is in service range → inbound
    return 'inbound'  if $rport >= 49152 && $lport  < 49152;

    # We use an ephemeral port (≥49152) → outbound client
    return 'outbound' if $lport >= 49152 && $rport  < 49152;

    # Both high ports: lower one is likely the service
    return ($lport <= $rport) ? 'inbound' : 'outbound';
}

sub _shortHostname {
    my $h = eval { Sys::Hostname::hostname() } // '';
    $h ||= do { my $o = `hostname 2>nul`; $o =~ s/\r?\n//g; $o };
    $h =~ s/\..*$//;   # strip domain part
    return $h;
}

sub _now {
    my @t = localtime(time);
    return sprintf '%04d-%02d-%02d %02d:%02d:%02d',
        $t[5]+1900, $t[4]+1, $t[3], $t[2], $t[1], $t[0];
}

# ── HTTP push ─────────────────────────────────────────────────────────────────

sub _push {
    my ($self, $cfg, $payload) = @_;

    my $logger = $self->{logger};

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
        ssl_opts => { verify_hostname => 0 },
    );

    my $req = HTTP::Request->new(POST => $cfg->{push_url});
    $req->header('Content-Type'    => 'application/json; charset=utf-8');
    $req->header('X-NetStat-Token' => $cfg->{push_token});
    $req->content(encode('UTF-8', $json));

    $logger->debug("NetStat: POST → $cfg->{push_url}");

    my $resp = $ua->request($req);
    if ($resp->is_success) {
        $logger->info('NetStat: push OK — ' . $resp->decoded_content);
    } else {
        $logger->error('NetStat: push FAILED ' . $resp->status_line
            . ' — ' . $resp->decoded_content);
    }
}

# ── Config reader ─────────────────────────────────────────────────────────────

sub _readConfig {
    my ($self) = @_;

    my @paths = (
        'C:/Program Files/GLPI-Agent/etc/conf.d/netstat.cfg',
        'C:/Program Files (x86)/GLPI-Agent/etc/conf.d/netstat.cfg',
        '/etc/glpi-agent/conf.d/netstat.cfg',
        '/usr/local/etc/glpi-agent/conf.d/netstat.cfg',
    );

    for my $path (@paths) {
        next unless -f $path;
        my %cfg;
        open my $fh, '<', $path or next;
        while (<$fh>) {
            s/#.*//;
            s/^\s+|\s+$//g;
            next unless /^(\S+)\s*=\s*(.+)$/;
            $cfg{$1} = $2;
        }
        close $fh;
        return \%cfg if $cfg{push_url} && $cfg{push_token};
    }

    return undef;
}

1;
