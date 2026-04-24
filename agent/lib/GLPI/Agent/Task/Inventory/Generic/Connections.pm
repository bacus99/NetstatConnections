package GLPI::Agent::Task::Inventory::Generic::Connections;

use strict;
use warnings;

use parent 'GLPI::Agent::Task::Inventory::Module';

use File::Spec;
use English qw(-no_match_vars);
use JSON::PP;

use constant    category    => "network";

sub isEnabled {
    my (%params) = @_;
    my $logger = $params{logger};
    $logger->debug("Connections module: enabled") if $logger;
    return 1;
}

sub doInventory {
    my (%params) = @_;

    my $inventory = $params{inventory};
    my $logger    = $params{logger};
    my $config    = $params{config};

    # ── Run collector ─────────────────────────────────────────────────
    my $agent_root = $ENV{GLPI_AGENT_ROOT} // 'C:/Program Files/GLPI-Agent';
    my $collector  = File::Spec->catfile($agent_root, 'glpi-netstat-collect.pl');
    my $perl       = File::Spec->catfile($agent_root, 'perl', 'bin', 'glpi-agent.exe');

    if (-f $collector && -f $perl) {
        $logger->info("Connections module: running collector...") if $logger;

        my $bin_dir = File::Spec->catfile($agent_root, 'perl', 'bin');
        local $ENV{PATH} = "$bin_dir;$ENV{PATH}";

        my $cmd    = "cd /d \"$agent_root\" && \"$perl\" \"$collector\"";
        my $output = `$cmd 2>&1`;
        my $exit   = $? >> 8;

        if ($exit != 0) {
            $logger->error("Connections module: collector exit code $exit") if $logger;
        }
    } else {
        $logger->debug("Connections module: collector not found, using existing cache")
            if $logger;
    }

    # ── Load cache ────────────────────────────────────────────────────
    my $data = _loadCache($config, $logger);
    unless ($data) {
        $logger->info("Connections module: no cache available") if $logger;
        return;
    }

    my $method = $data->{collection_method} // 'netstat';
    $logger->debug("Connections module: cache schema=$data->{schema_version} method=$method")
        if $logger;

    # ── Inject connections ────────────────────────────────────────────
    my $conn_count = 0;
    for my $c (@{$data->{connections} // []}) {
        my $entry = {
            PROTOCOL          => $c->{protocol}        // '',
            LOCAL_ADDR        => $c->{local_addr}      // '',
            LOCAL_PORT        => $c->{local_port}      // 0,
            REMOTE_ADDR       => $c->{remote_addr}     // '',
            REMOTE_PORT       => $c->{remote_port}     // 0,
            REMOTE_HOSTNAME   => $c->{remote_hostname} // '',
            PROCESS_NAME      => $c->{process_name}    // '',
            SERVICE_NAME      => $c->{service_name}    // '',
            STATE             => $c->{state}           // '',
            CREATED_AT        => $c->{created_at}      // '',
            OFFLOAD_STATE     => $c->{offload_state}   // '',
            APPLIED_SETTING   => $c->{applied_setting} // '',
            COLLECTION_METHOD => $method,
            CONN_DIRECTION    => $c->{conn_direction}   // 'outbound',
            SERVICE_PORT      => $c->{service_port}     // $c->{remote_port} // 0,
        };
        _clean($entry);

        eval {
            $inventory->addEntry(
                section => 'NETWORK_CONNECTIONS',
                entry   => $entry,
            );
        };
        if ($@) {
            $logger->debug("Connections module: addEntry skipped: $@") if $logger;
            last;
        }
        $conn_count++;
    }

    # ── Inject listening ports ────────────────────────────────────────
    my $port_count = 0;
    for my $p (@{$data->{listening} // []}) {
        my $entry = {
            PROTOCOL     => $p->{protocol}     // '',
            LOCAL_ADDR   => $p->{local_addr}   // '',
            LOCAL_PORT   => $p->{local_port}   // 0,
            PROCESS_NAME => $p->{process_name} // '',
            SERVICE_NAME => $p->{service_name} // '',
            CREATED_AT   => $p->{created_at}   // '',
        };
        _clean($entry);

        eval {
            $inventory->addEntry(
                section => 'LISTENING_PORTS',
                entry   => $entry,
            );
        };
        if ($@) {
            $logger->debug("Connections module: LISTENING_PORTS skipped: $@") if $logger;
            last;
        }
        $port_count++;
    }

    $logger->info("Connections module: sent $conn_count connections, $port_count listening ports [$method]")
        if $logger;
}

sub _loadCache {
    my ($config, $logger) = @_;
    my $vardir = ($config && $config->{vardir})
        ? $config->{vardir}
        : ($ENV{GLPI_AGENT_ROOT} // 'C:/Program Files/GLPI-Agent') . '/var';
    my $cache_file = File::Spec->catfile($vardir, 'netstat-cache.json');

    open(my $fh, '<', $cache_file) or do {
        $logger->debug("Connections module: no cache at $cache_file") if $logger;
        return undef;
    };
    local $/;
    my $json = <$fh>;
    close $fh;

    my $data = eval { decode_json($json) };
    if ($@) {
        $logger->error("Connections module: JSON parse error: $@") if $logger;
        return undef;
    }
    return $data;
}

sub _clean {
    my ($h) = @_;
    delete $h->{$_} for grep { !defined $h->{$_} || $h->{$_} eq '' } keys %$h;
}

1;
