<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Data\Security\Finding;
use App\Models\Server;

/**
 * Audits a server the way a security reviewer would: SSH settings, the firewall and what's open to the internet,
 * brute-force protection, pending and automatic security updates, a pending reboot, and the Ubuntu release. One
 * read-only script gathers the facts as KEY=value lines; findings name the one-click fix that applies, if any.
 */
final class ServerAudit
{
    /**
     * Services that should never be reachable from the whole internet, by port.
     *
     * @var array<int, string>
     */
    private const RISKY_PORTS = [3306 => 'MySQL', 5432 => 'PostgreSQL', 6379 => 'Redis', 11211 => 'Memcached', 27017 => 'MongoDB', 9200 => 'Elasticsearch', 7700 => 'Meilisearch', 8108 => 'Typesense', 2375 => 'Docker API', 5672 => 'RabbitMQ', 15672 => 'RabbitMQ management'];

    /**
     * Get the read-only script that gathers the facts.
     *
     * @return string
     */
    public function script(): string
    {
        return <<<'BASH'
        sshd_value() { sshd -T 2>/dev/null | awk -v key="$1" '$1 == key { print $2; exit }'; }
        echo "PASSWORD_AUTH=$(sshd_value passwordauthentication)"
        echo "ROOT_LOGIN=$(sshd_value permitrootlogin)"
        echo "UFW=$(ufw status 2>/dev/null | head -n 1 | awk '{print $2}')"
        ufw status 2>/dev/null | awk '/ALLOW/ && /Anywhere/ && !/\(v6\)/ { print "UFW_ALLOW=" $1 }'
        echo "FAIL2BAN=$(systemctl is-active fail2ban 2>/dev/null || echo inactive)"
        echo "UNATTENDED=$(grep -hs 'Unattended-Upgrade "1"' /etc/apt/apt.conf.d/20auto-upgrades /etc/apt/apt.conf.d/50unattended-upgrades >/dev/null && dpkg -s unattended-upgrades >/dev/null 2>&1 && echo on || echo off)"
        upgradable="$(apt-get -s -o Debug::NoLocking=1 upgrade 2>/dev/null | grep '^Inst' || true)"
        echo "UPDATES=$(printf '%s' "$upgradable" | grep -c . || true)"
        echo "SECURITY_UPDATES=$(printf '%s' "$upgradable" | grep -ci security || true)"
        echo "REBOOT=$([ -f /var/run/reboot-required ] && echo yes || echo no)"
        echo "UBUNTU=$( (lsb_release -rs 2>/dev/null) || (. /etc/os-release 2>/dev/null; echo "${VERSION_ID:-}") )"
        ss -tlnH 2>/dev/null | awk '{print "LISTEN=" $4}'
        BASH;
    }

    /**
     * Turn the script's output into findings.
     *
     * @param  Server  $server
     * @param  string  $output
     * @return list<Finding>
     */
    public function findings(Server $server, string $output): array
    {
        $facts = [];
        $lists = ['LISTEN' => [], 'UFW_ALLOW' => []];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', trim($line), $parts) === 1) {
                if (isset($lists[$parts[1]])) {
                    $lists[$parts[1]][] = $parts[2];
                } else {
                    $facts[$parts[1]] = $parts[2];
                }
            }
        }
        $name = $server->display_name ?: $server->name;
        $findings = [];
        $finding = fn (string $key, string $severity, string $title, ?string $detail, ?string $fix, ?string $action = null): Finding => new Finding($key, $severity, $title, $detail, $name, fix: $fix, data: ['server_id' => $server->id, 'fix_action' => $action]);

        if (($facts['PASSWORD_AUTH'] ?? '') === 'yes') {
            $findings[] = $finding('ssh-password', 'high', (string) __(':server accepts SSH passwords', ['server' => $name]), (string) __('Passwords can be guessed; keys can’t.'), (string) __('Allow SSH keys only.'), 'ssh-keys-only');
        }
        if (($facts['ROOT_LOGIN'] ?? '') === 'yes') {
            $findings[] = $finding('root-password', 'high', (string) __(':server lets root sign in with a password', ['server' => $name]), null, (string) __('Let root sign in with a key only.'), 'root-keys-only');
        }
        $firewallOn = ($facts['UFW'] ?? '') === 'active';
        if (! $firewallOn) {
            $findings[] = $finding('firewall-off', 'high', (string) __(':server’s firewall is off', ['server' => $name]), (string) __('Everything listening on the server can be reached from the internet.'),
                (string) __('Turn the firewall on, allowing only SSH, the web and your own firewall rules.'), 'enable-firewall');
        }
        $allowed = array_map(fn (string $port): string => explode('/', $port)[0], $lists['UFW_ALLOW']);
        foreach (array_unique($lists['LISTEN']) as $address) {
            if (preg_match('/^(0\.0\.0\.0|\*|\[::\]|::):(\d+)$/', $address, $parts) !== 1) {
                continue;
            }
            $port = (int) $parts[2];
            if (in_array($port, [22, 80, 443, $server->ssh_port], true) || ($firewallOn && ! in_array((string) $port, $allowed, true))) {
                continue;
            }
            $service = self::RISKY_PORTS[$port] ?? null;
            $findings[] = $service === null
                ? $finding("open-port-{$port}", 'low', (string) __('Port :port on :server is open to the internet', ['port' => $port, 'server' => $name]), null, (string) __('Close the port in the firewall, or limit it to the addresses that need it.'))
                : $finding("open-port-{$port}", 'critical', (string) __(':service on :server can be reached from the internet', ['service' => $service, 'server' => $name]), (string) __(':service listens on port :port for every address.', ['service' => $service, 'port' => $port]),
                    (string) __('Make :service listen on 127.0.0.1 or the private network only, and close port :port in the firewall.', ['service' => $service, 'port' => $port]));
        }
        if (($facts['FAIL2BAN'] ?? 'inactive') !== 'active') {
            $findings[] = $finding('fail2ban', 'medium', (string) __(':server doesn’t block repeated failed sign-ins', ['server' => $name]), (string) __('fail2ban isn’t running, so password guessing is only limited by SSH itself.'), (string) __('Install fail2ban.'), 'install-fail2ban');
        }
        if (($facts['UNATTENDED'] ?? 'off') !== 'on') {
            $findings[] = $finding('auto-updates', 'medium', (string) __(':server doesn’t install security updates automatically', ['server' => $name]), null, (string) __('Turn on automatic security updates, or set an update window in Security → Servers.'), 'enable-auto-updates');
        }
        $security = (int) ($facts['SECURITY_UPDATES'] ?? 0);
        if ($security > 0) {
            $findings[] = $finding('pending-security-updates', $security >= 10 ? 'high' : 'medium', trans_choice(':server has :count security update waiting|:server has :count security updates waiting', $security, ['server' => $name, 'count' => $security]),
                (string) __(':total updates in all.', ['total' => (int) ($facts['UPDATES'] ?? $security)]), (string) __('Apply the updates now, or set an update window.'), 'apply-updates');
        }
        if (($facts['REBOOT'] ?? 'no') === 'yes') {
            $findings[] = $finding('reboot-required', 'low', (string) __(':server needs a reboot to finish updating', ['server' => $name]), (string) __('Updated libraries or the kernel only take effect after a restart.'), (string) __('Reboot at a quiet time, or let the update window reboot it.'), 'reboot');
        }
        $release = (float) ($facts['UBUNTU'] ?? 0);
        if ($release > 0 && $release < 22.04) {
            $findings[] = $finding('old-ubuntu', $release < 20.04 ? 'critical' : 'high', (string) __(':server runs Ubuntu :release, which is at or near the end of its support', ['server' => $name, 'release' => $facts['UBUNTU']]), null,
                (string) __('Move the websites to a new server on a supported Ubuntu release.'));
        }

        return $findings;
    }
}
