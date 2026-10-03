<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\Server;
use App\Models\ServerFirewallRule;
use App\Services\Infrastructure\ServerTaskScripts;
use InvalidArgumentException;

/**
 * The one-click fixes for server findings. Each keeps BuildPusher's own access working: SSH stays on with keys (root
 * included, since that's how BuildPusher connects), and the firewall always lets SSH through first.
 */
final class HardeningScripts
{
    /**
     * The fixes, with what each does in a sentence.
     *
     * @var array<string, string>
     */
    public const ACTIONS = [
        'ssh-keys-only' => 'Allow SSH keys only',
        'root-keys-only' => 'Let root sign in with a key only',
        'enable-firewall' => 'Turn the firewall on',
        'install-fail2ban' => 'Install fail2ban',
        'enable-auto-updates' => 'Turn on automatic security updates',
        'apply-updates' => 'Apply updates now',
        'reboot' => 'Reboot now',
    ];

    /**
     * Create a new HardeningScripts instance.
     *
     * @param  ServerTaskScripts  $tasks  Writes the server's own firewall rules back.
     */
    public function __construct(private readonly ServerTaskScripts $tasks) {}

    /**
     * Get the script for a fix on a server.
     *
     * @param  string  $action  one of ACTIONS' keys
     * @param  Server  $server
     * @return string
     */
    public function script(string $action, Server $server): string
    {
        $wait = 'while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; do sleep 3; done; export DEBIAN_FRONTEND=noninteractive';

        return match ($action) {
            'ssh-keys-only' => $this->sshd("PasswordAuthentication no\nKbdInteractiveAuthentication no\nPubkeyAuthentication yes\nPermitEmptyPasswords no"),
            'root-keys-only' => $this->sshd('PermitRootLogin prohibit-password', '99-buildpusher-root.conf'),
            'enable-firewall' => $this->firewall($server),
            'install-fail2ban' => "{$wait}\napt-get update -qq\napt-get install -y -qq fail2ban\nsystemctl enable --now fail2ban",
            'enable-auto-updates' => "{$wait}\napt-get update -qq\napt-get install -y -qq unattended-upgrades\n"
                ."printf 'APT::Periodic::Update-Package-Lists \"1\";\\nAPT::Periodic::Unattended-Upgrade \"1\";\\n' > /etc/apt/apt.conf.d/20auto-upgrades\n"
                .'systemctl enable --now unattended-upgrades',
            'apply-updates' => $this->updates(false),
            'reboot' => 'shutdown -r +1 "Rebooting to finish updates (BuildPusher Security)"',
            default => throw new InvalidArgumentException("Unknown fix {$action}."),
        };
    }

    /**
     * Get the script that installs pending security updates (all updates when unattended-upgrades isn't available),
     * and optionally reboots afterwards if the updates need it.
     *
     * @param  bool  $reboot
     * @return string
     */
    public function updates(bool $reboot): string
    {
        $script = <<<'BASH'
        while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; do sleep 3; done
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq
        if command -v unattended-upgrade >/dev/null 2>&1; then
            unattended-upgrade -v
        else
            apt-get -y -o Dpkg::Options::=--force-confold -o Dpkg::Options::=--force-confdef upgrade
        fi
        BASH;

        return $reboot ? $script."\nif [ -f /var/run/reboot-required ]; then shutdown -r +1 \"Rebooting after security updates (BuildPusher)\"; fi" : $script;
    }

    /**
     * Get commands that write an SSH drop-in, check the configuration, and reload SSH (undoing the drop-in if SSH
     * rejects it, so a bad setting can never lock anyone out).
     *
     * @param  string  $settings
     * @param  string  $file
     * @return string
     */
    private function sshd(string $settings, string $file = '99-buildpusher.conf'): string
    {
        $path = escapeshellarg("/etc/ssh/sshd_config.d/{$file}");
        $content = escapeshellarg($settings."\n");

        return <<<BASH
        install -d -m 755 /etc/ssh/sshd_config.d
        [ -f {$path} ] && cp -p {$path} {$path}.bak
        printf '%s' {$content} > {$path}
        if sshd -t; then
            systemctl reload ssh || systemctl reload sshd
        else
            if [ -f {$path}.bak ]; then mv {$path}.bak {$path}; else rm -f {$path}; fi
            echo "SSH rejected the new settings; nothing was changed." >&2
            exit 1
        fi
        BASH;
    }

    /**
     * Get commands that turn the firewall on with SSH allowed first, the web ports for servers that serve websites,
     * and the server's own firewall rules.
     *
     * @param  Server  $server
     * @return string
     */
    private function firewall(Server $server): string
    {
        $port = (int) $server->ssh_port ?: 22;
        $rules = ServerFirewallRule::query()->where('server_id', $server->id)->get()
            ->map(fn (ServerFirewallRule $rule): string => $this->tasks->apply($rule))->implode("\n");

        return <<<BASH
        command -v ufw >/dev/null 2>&1 || { export DEBIAN_FRONTEND=noninteractive; apt-get update -qq && apt-get install -y -qq ufw; }
        ufw allow {$port}/tcp
        ufw allow 80/tcp
        ufw allow 443/tcp
        {$rules}
        ufw default deny incoming
        ufw default allow outgoing
        ufw --force enable
        BASH;
    }
}
