package GLPI::Agent::Task::Inventory::Win32::WinServerRoles;

use strict;
use warnings;

use parent 'GLPI::Agent::Task::Inventory::Module';

use English      qw(-no_match_vars);
use Encode       qw(encode);
use MIME::Base64 qw(encode_base64);
use JSON::PP;

=head1 NAME

GLPI::Agent::Task::Inventory::Win32::WinServerRoles

=head1 DESCRIPTION

Collects Windows Server roles and features via Get-WindowsFeature (PowerShell /
ServerManager module) and adds them as a C<WIN_SERVER_ROLES> section to the
standard GLPI Agent inventory payload.

The section is extracted and persisted by the C<winserverroles> GLPI plugin
via its C<pre_inventory> hook, before GLPI schema validation runs.

Compatible with: Windows Server 2012 R2 and later (Get-WindowsFeature
requires RSAT or ServerManager module — not available on Desktop SKUs).

Deploy to: C<C:\Program Files\GLPI-Agent\perl\agent\GLPI\Agent\Task\Inventory\Win32\WinServerRoles.pm>

=cut

# Only loaded on Windows agents
our $runMeIfNoOS = 'win';

# ---------------------------------------------------------------------------
# isEnabled
# ---------------------------------------------------------------------------

sub isEnabled {
    # Let _getWindowsFeatures() decide at runtime via Import-Module failure.
    # This avoids a redundant WMI call for SKU detection.
    return 1;
}

# ---------------------------------------------------------------------------
# doInventory
# ---------------------------------------------------------------------------

sub doInventory {
    my (%params) = @_;

    my $inventory = $params{inventory};
    my $logger    = $params{logger};

    my @roles = _getWindowsFeatures($logger);

    unless (@roles) {
        $logger->debug2('WinServerRoles: no roles collected'
            . ' — host may not be a Windows Server SKU');
        return;
    }

    # NOTE: GLPI 11's inventory endpoint does NOT load plugin setup.php files,
    # so the PRE_INVENTORY hook approach (custom WIN_SERVER_ROLES section
    # stripped server-side) does not work — GLPI rejects unknown sections with
    # a 500 before any plugin code runs.  Instead we embed each role as a
    # SOFTWARES entry with a recognizable prefix; a CLI scheduled task on the
    # GLPI server (where plugins ARE loaded) scans these and syncs them to
    # the plugin's roles table.
    #
    # Only INSTALLED roles are emitted — non-installed features would create
    # noise in the standard software inventory.
    my $tag = '[WinServerRole]';
    my $count = 0;

    for my $role (@roles) {
        next unless $role->{INSTALLED};

        my $display = $role->{DISPLAYNAME} || $role->{NAME};
        my $name    = "$tag $display";

        # COMMENTS carries the structured payload the server-side task parses.
        # Pipe-delimited: NAME|DISPLAYNAME|INSTALLED|SUBFEATURES
        my $comments = join('|',
            $role->{NAME}        // '',
            $role->{DISPLAYNAME} // '',
            $role->{INSTALLED}   ? 1 : 0,
            $role->{SUBFEATURES} // '',
        );

        $inventory->addEntry(
            section => 'SOFTWARES',
            entry   => {
                NAME      => $name,
                PUBLISHER => 'Microsoft Windows Server',
                COMMENTS  => $comments,
            },
        );
        $count++;
    }

    $logger->debug("WinServerRoles: emitted $count installed role(s) as SOFTWARES entries");
}

# ---------------------------------------------------------------------------
# _getWindowsFeatures  (private)
# ---------------------------------------------------------------------------

sub _getWindowsFeatures {
    my ($logger) = @_;

    # PowerShell script run via -EncodedCommand (UTF-16LE Base64) to avoid
    # all shell-quoting issues on Windows.
    #
    # Notes:
    #   - [Console]::OutputEncoding = UTF8 ensures JSON arrives as UTF-8.
    #   - Import-Module ServerManager -ErrorAction Stop exits with code 1
    #     on Desktop SKUs — no output, caught by the JSON check below.
    #   - @($result) wraps the pipeline output in an array so that
    #     ConvertTo-Json always emits a JSON array, even for a single item.
    #   - SubFeatures is a StringCollection; .Name -join ';' produces a
    #     semicolon-delimited string (empty string when there are none).
    my $script = <<'END_PS';
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
try {
    Import-Module ServerManager -ErrorAction Stop
    $result = Get-WindowsFeature | Select-Object `
        Name, DisplayName, Description, Installed,
        @{ N='SubFeatures'; E={ ($_.SubFeatures.Name) -join ';' } }
    ConvertTo-Json -InputObject @($result) -Compress -Depth 2
} catch {
    exit 1
}
END_PS

    # Encode to UTF-16LE Base64 for -EncodedCommand
    my $encoded = eval { encode_base64(encode('UTF-16LE', $script), '') };
    if ($@ || !$encoded) {
        $logger->debug("WinServerRoles: encoding error: $@");
        return ();
    }

    # Run PowerShell; suppress stderr (expected on non-Server SKUs)
    my $json = qx{powershell.exe -NonInteractive -NoProfile -EncodedCommand $encoded 2>nul};

    # Quick sanity check before attempting JSON decode
    unless (defined $json && $json =~ /\[/) {
        $logger->debug2('WinServerRoles: no JSON array in PowerShell output');
        return ();
    }

    # Strip UTF-8 BOM if present (PowerShell can emit one)
    $json =~ s/^\xef\xbb\xbf//;

    my $data = eval { JSON::PP->new->utf8->decode($json) };
    if ($@) {
        $logger->debug("WinServerRoles: JSON parse error: $@");
        return ();
    }

    return () unless ref($data) eq 'ARRAY' && @{$data};

    my @roles;
    for my $f (@{$data}) {
        next unless ref($f) eq 'HASH';
        push @roles, {
            NAME        => $f->{Name}        // '',
            DISPLAYNAME => $f->{DisplayName} // '',
            DESCRIPTION => $f->{Description} // '',
            INSTALLED   => ($f->{Installed} ? 1 : 0),
            SUBFEATURES => $f->{SubFeatures} // '',
        };
    }

    return @roles;
}

1;
