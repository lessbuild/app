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
     * Where the setting that lets automatic updates install PHP is written.
     *
     * @var string
     */
    public const PHP_UPDATES_FILE = '/etc/apt/apt.conf.d/51buildpusher-php';

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
                .self::phpUpdateSource()."\n"
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
        php_before="$(dpkg-query -W 'php*' 2>/dev/null)"
        if command -v unattended-upgrade >/dev/null 2>&1; then
            unattended-upgrade -v
        else
            apt-get -y -o Dpkg::Options::=--force-confold -o Dpkg::Options::=--force-confdef upgrade
        fi
        # PHP comes from ppa:ondrej/php, which unattended-upgrades skips unless told otherwise, so update it explicitly.
        php_packages="$(dpkg-query -W -f='${db:Status-Abbrev} ${Package}\n' 'php*' 2>/dev/null | awk '$1 == "ii" { print $2 }')"
        if [ -n "$php_packages" ]; then
            apt-get -y -o Dpkg::Options::=--force-confold -o Dpkg::Options::=--force-confdef --only-upgrade install $php_packages
        fi
        # Servers set up before 2026-10-22 had redis.ini rewritten without its priority, so PHP loaded redis twice
        # ("Module "redis" is already loaded"). Put the package's file back and drop the duplicate.
        for ini in /etc/php/*/mods-available/redis.ini; do
            if [ -f "$ini" ] && ! grep -q 'priority' "$ini"; then printf '; priority=25\nextension=redis.so\n' > "$ini"; fi
        done
        for dir in /etc/php/*/*/conf.d; do
            if [ -e "$dir/25-redis.ini" ]; then rm -f -- "$dir/20-redis.ini"; fi
        done
        # Running PHP keeps the old, vulnerable code in memory until it restarts: PHP-FPM and the queue workers.
        if [ "$php_before" != "$(dpkg-query -W 'php*' 2>/dev/null)" ]; then
            systemctl try-restart 'php*-fpm.service' || true
            if command -v supervisorctl >/dev/null 2>&1; then supervisorctl restart all >/dev/null || true; fi
        fi
        BASH;
        $script = self::phpUpdateSource()."\n".$script;

        return $reboot ? $script."\nif [ -f /var/run/reboot-required ]; then shutdown -r +1 \"Rebooting after security updates (BuildPusher)\"; fi" : $script;
    }

    /**
     * Get the command that lets automatic security updates install PHP from ppa:ondrej/php, where BuildPusher
     * installs it. Without it unattended-upgrades only takes Ubuntu's own packages and PHP never gets patched.
     *
     * @return string
     */
    public static function phpUpdateSource(): string
    {
        return "printf '%s\\n' '// Written by BuildPusher: automatic security updates also install PHP from ppa:ondrej/php.' "
            ."'Unattended-Upgrade::Origins-Pattern { \"origin=LP-PPA-ondrej-php\"; };' > ".self::PHP_UPDATES_FILE;
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
