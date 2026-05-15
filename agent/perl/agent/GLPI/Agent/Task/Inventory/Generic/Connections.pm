package GLPI::Agent::Task::Inventory::Generic::Connections;

use strict;
use warnings;

use parent 'GLPI::Agent::Task::Inventory::Module';

use File::Spec;
use English qw(-no_match_vars);
use JSON::PP;
use HTTP::Request;
use LWP::UserAgent;

use constant    category    => "network";

# ── Constants ────────────────────────────────────────────────────────────────
my $AGENT_ROOT_DEFAULT = 'C:/Program Files/GLPI-Agent';

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
    my $agent_root = $ENV{GLPI_AGENT_ROOT} // $AGENT_ROOT_DEFAULT;
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

    # ── Push to GLPI plugin endpoint ─────────────────────────────────
    # We do NOT inject into the inventory payload — GLPI 11's stateless
    # inventory route bootstrap-skips plugins before the pre_inventory
    # hook fires, so custom keys would be rejected by schema validation.
    # Instead we POST a separate request to the plugin's push.php which
    # is exempt from session/auth (uses Bearer token).
    my $pushed = _push($config, $logger, $data, $method);

    if ($pushed) {
        my $cn = scalar @{$data->{connections} // []};
        my $pn = scalar @{$data->{listening}   // []};
        $logger->info("Connections module: pushed $cn connections, $pn listening ports [$method]")
            if $logger;
    }
}

sub _loadCache {
    my ($config, $logger) = @_;
    my $vardir = ($config && $config->{vardir})
        ? $config->{vardir}
        : ($ENV{GLPI_AGENT_ROOT} // $AGENT_ROOT_DEFAULT) . '/var';
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

# ── Push connections to GLPI plugin endpoint ─────────────────────────
sub _push {
    my ($config, $logger, $data, $method) = @_;

    # Resolve GLPI server URL — try several places since the inventory module's
    # $config object is transport-agnostic and doesn't expose `server` directly.
    my $glpi_url = _findServerUrl($config, $logger);
    unless ($glpi_url) {
        $logger->error("Connections module: could not determine GLPI server URL — skipping push")
            if $logger;
        return 0;
    }
    # Normalize URL — agents may point at glpiinventory plugin paths, fusioninventory,
    # legacy /front/inventory.php endpoints, etc. Strip everything down to the GLPI
    # root since our plugin lives at /plugins/netstatconnections/. Canonical form
    # has NO trailing slash (e.g. "https://glpi.example.com/glpi").
    $glpi_url =~ s{/+$}{};                                         # collapse trailing slashes
    $glpi_url =~ s{/front/inventory\.php$}{}i;                     # legacy core
    $glpi_url =~ s{/marketplace/glpiinventory/?$}{}i;              # marketplace glpiinventory
    $glpi_url =~ s{/plugins/glpiinventory/?$}{}i;                  # plugins glpiinventory
    $glpi_url =~ s{/plugins/fusioninventory(/.*)?$}{}i;            # legacy fusioninventory + subpaths
    $glpi_url =~ s{/marketplace/fusioninventory(/.*)?$}{}i;        # marketplace fusion
    $glpi_url =~ s{/front/communication\.php$}{}i;                 # fusion legacy endpoint
    $glpi_url =~ s{/+$}{};                                         # re-collapse
    # Catch-all: strip any remaining /marketplace/<plugin> or /plugins/<plugin> paths
    $glpi_url =~ s{/marketplace/[^/]+/?$}{}i;
    $glpi_url =~ s{/plugins/[^/]+/?$}{}i;
    $glpi_url =~ s{/marketplace/[^/]+/.*$}{}i;
    $glpi_url =~ s{/plugins/[^/]+/.*$}{}i;
    $glpi_url =~ s{/+$}{};                                         # final collapse
    $logger->debug("Connections module: normalized GLPI base URL: $glpi_url") if $logger;

    my $config_url = "$glpi_url/plugins/netstatconnections/front/agentconfig.php";
    my $push_url   = "$glpi_url/plugins/netstatconnections/front/push.php";

    # Fetch the push token from agentconfig.php
    my $ua = LWP::UserAgent->new(
        timeout => 30,
        ssl_opts => { verify_hostname => 0, SSL_verify_mode => 0x00 },
    );
    $ua->agent("GLPI-Agent-NetstatConnections/2.1.0");

    my $cfg_resp = $ua->get($config_url);
    unless ($cfg_resp->is_success) {
        $logger->error("Connections module: failed to fetch agent config from $config_url ("
            . $cfg_resp->status_line . ")") if $logger;
        return 0;
    }

    my $server_cfg = eval { decode_json($cfg_resp->decoded_content) };
    if ($@ || ref($server_cfg) ne 'HASH') {
        $logger->error("Connections module: invalid JSON from agentconfig.php: $@") if $logger;
        return 0;
    }

    my $token = $server_cfg->{push_token} // '';
    unless ($token) {
        $logger->error("Connections module: agentconfig.php returned no push_token") if $logger;
        return 0;
    }

    # Determine hostname
    my $hostname = $data->{hostname};
    unless ($hostname) {
        # Fallback: from environment / system
        $hostname = $ENV{COMPUTERNAME} // `hostname`;
        chomp $hostname if defined $hostname;
    }

    # Build payload — include token in body too, since Apache often strips
    # the Authorization header before PHP sees it (mod_php-fpm + proxy quirks)
    my $payload = {
        hostname          => $hostname,
        collected_at      => $data->{collected_at} // _now(),
        collection_method => $method,
        connections       => $data->{connections} // [],
        listening         => $data->{listening}   // [],
        token             => $token,
    };

    my $json = encode_json($payload);

    my $req = HTTP::Request->new(POST => $push_url);
    $req->header('Content-Type'  => 'application/json');
    $req->header('Authorization' => "Bearer $token");
    $req->content($json);

    my $resp = $ua->request($req);
    if (!$resp->is_success) {
        $logger->error("Connections module: push failed " . $resp->status_line
            . " from $push_url: " . $resp->decoded_content) if $logger;
        return 0;
    }

    $logger->debug("Connections module: push response: " . $resp->decoded_content)
        if $logger;
    return 1;
}

sub _now {
    my @t = localtime;
    return sprintf("%04d-%02d-%02d %02d:%02d:%02d",
        $t[5] + 1900, $t[4] + 1, $t[3], $t[2], $t[1], $t[0]);
}

# ── Resolve GLPI server URL ──────────────────────────────────────────
# Tries (in order):
#   1. $config->{server} as hashref or arrayref (some agent versions)
#   2. Method calls on $config object (newer agent versions)
#   3. Windows registry HKLM\SOFTWARE\GLPI-Agent
#   4. agent.cfg file in $agent_root/etc/
#   5. Any conf.d/*.cfg overrides
sub _findServerUrl {
    my ($config, $logger) = @_;

    # Try 1: hash access
    if (ref($config) eq 'HASH' && $config->{server}) {
        my $s = $config->{server};
        return ref($s) eq 'ARRAY' ? $s->[0] : $s if $s;
    }
    # Try 2: object access
    if (ref($config) && $config->can('getValues')) {
        my $v = eval { $config->getValues() };
        if (ref($v) eq 'HASH' && $v->{server}) {
            my $s = $v->{server};
            return ref($s) eq 'ARRAY' ? $s->[0] : $s if $s;
        }
    }
    if (ref($config)) {
        for my $key (qw(server _server)) {
            my $s = eval { $config->{$key} };
            return $s if $s && !ref($s);
            return $s->[0] if ref($s) eq 'ARRAY' && @$s;
        }
    }

    # Try 3: Windows registry via `reg query` (no Perl module dependency)
    if ($^O eq 'MSWin32') {
        for my $hive ('HKLM', 'HKCU') {
            my $output = `reg query $hive\\SOFTWARE\\GLPI-Agent /v server 2>nul`;
            if ($output && $output =~ /server\s+REG_SZ\s+(\S.+?)\s*$/m) {
                my $url = $1;
                $url =~ s/\s+$//;
                if ($url) {
                    $logger->debug("Connections module: server URL from registry ($hive): $url") if $logger;
                    return $url;
                }
            }
        }
    }

    # Try 4 + 5: config files
    my $agent_root = $ENV{GLPI_AGENT_ROOT} // $AGENT_ROOT_DEFAULT;
    my @candidates = (
        File::Spec->catfile($agent_root, 'etc', 'agent.cfg'),
    );
    my $confd = File::Spec->catfile($agent_root, 'etc', 'conf.d');
    if (-d $confd) {
        opendir(my $dh, $confd);
        while (my $f = readdir($dh)) {
            push @candidates, File::Spec->catfile($confd, $f) if $f =~ /\.cfg$/;
        }
        closedir($dh);
    }
    for my $cfg (@candidates) {
        next unless -r $cfg;
        open(my $fh, '<', $cfg) or next;
        while (my $line = <$fh>) {
            next if $line =~ /^\s*#/;
            if ($line =~ /^\s*server\s*=\s*(\S+)/) {
                close $fh;
                $logger->debug("Connections module: server URL from $cfg: $1") if $logger;
                return $1;
            }
        }
        close $fh;
    }

    return undef;
}

1;
