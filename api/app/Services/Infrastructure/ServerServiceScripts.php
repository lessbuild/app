<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\ServerService;
use InvalidArgumentException;

/**
 * The shell commands that install, reconfigure and remove a server's one-click services. Each listens on 127.0.0.1,
 * and also on the server's private IP when it should be reachable from the account's other servers; the firewall
 * still decides who may connect.
 */
final class ServerServiceScripts
{
    /**
     * The Typesense release installed.
     *
     * @var string
     */
    public const TYPESENSE_VERSION = '29.0';

    /**
     * Get the commands that install (or reconfigure) a service and start it.
     *
     * @param  ServerService  $service
     * @return string
     */
    public function install(ServerService $service): string
    {
        $bind = $this->bindAddresses($service);

        return "set -Eeuo pipefail\nexport DEBIAN_FRONTEND=noninteractive\n".match ($service->kind) {
            'meilisearch' => $this->meilisearch($service, $bind),
            'typesense' => $this->typesense($service, $bind),
            'redis' => $this->redis($service, $bind),
            default => throw new InvalidArgumentException("Unknown service {$service->kind}."),
        };
    }

    /**
     * Get the commands that stop a service and remove it (its data is kept in case it's installed again).
     *
     * @param  ServerService  $service
     * @return string
     */
    public function remove(ServerService $service): string
    {
        return match ($service->kind) {
            'meilisearch' => "systemctl disable --now meilisearch || true\nrm -f /etc/systemd/system/meilisearch.service /usr/local/bin/meilisearch\nsystemctl daemon-reload",
            'typesense' => "systemctl disable --now typesense-server || true\napt-get remove -y -qq typesense-server || true",
            'redis' => "systemctl disable --now redis-server || true\napt-get remove -y -qq redis-server || true",
            default => throw new InvalidArgumentException("Unknown service {$service->kind}."),
        };
    }

    /**
     * Get the addresses a service listens on: always 127.0.0.1, and the private IP when shared.
     *
     * @param  ServerService  $service
     * @return list<string>
     */
    private function bindAddresses(ServerService $service): array
    {
        $private = $service->server->private_ip;

        return $service->listen === 'private' && is_string($private) && filter_var($private, FILTER_VALIDATE_IP) !== false ? ['127.0.0.1', $private] : ['127.0.0.1'];
    }

    /**
     * Install Meilisearch's latest release for the server's architecture as a systemd service with a master key.
     *
     * @param  ServerService  $service
     * @param  list<string>  $bind
     * @return string
     */
    private function meilisearch(ServerService $service, array $bind): string
    {
        // Meilisearch listens on one address; with a private IP it listens on all and the firewall limits who gets in.
        $address = count($bind) > 1 ? '0.0.0.0' : '127.0.0.1';
        $unit = implode("\n", [
            '[Unit]', 'Description=Meilisearch (managed by BuildPusher)', 'After=network.target', '',
            '[Service]', 'User=meilisearch', 'WorkingDirectory=/var/lib/meilisearch',
            "ExecStart=/usr/local/bin/meilisearch --env production --http-addr {$address}:{$service->port} --db-path /var/lib/meilisearch/data --master-key \${MEILI_MASTER_KEY}",
            'EnvironmentFile=/etc/meilisearch.env', 'Restart=always', '',
            '[Install]', 'WantedBy=multi-user.target', '',
        ]);

        return implode("\n", [
            'case "$(uname -m)" in aarch64|arm64) ARCH=aarch64 ;; *) ARCH=amd64 ;; esac',
            'if [ ! -x /usr/local/bin/meilisearch ]; then curl -fsSL -o /usr/local/bin/meilisearch "https://github.com/meilisearch/meilisearch/releases/latest/download/meilisearch-linux-${ARCH}"; chmod 0755 /usr/local/bin/meilisearch; fi',
            'id -u meilisearch >/dev/null 2>&1 || useradd --system --home /var/lib/meilisearch --shell /usr/sbin/nologin meilisearch',
            'install -d -o meilisearch -g meilisearch -m 0750 /var/lib/meilisearch',
            $this->write('/etc/meilisearch.env', 'MEILI_MASTER_KEY='.$service->secret."\n", '0600'),
            $this->write('/etc/systemd/system/meilisearch.service', $unit, '0644'),
            'systemctl daemon-reload',
            'systemctl enable meilisearch >/dev/null',
            'systemctl restart meilisearch',
        ]);
    }

    /**
     * Install Typesense from its package and set its API key and addresses.
     *
     * @param  ServerService  $service
     * @param  list<string>  $bind
     * @return string
     */
    private function typesense(ServerService $service, array $bind): string
    {
        $version = self::TYPESENSE_VERSION;
        $config = implode("\n", [
            '; Managed by BuildPusher.', '[server]', 'api-address = '.(count($bind) > 1 ? '0.0.0.0' : '127.0.0.1'), 'api-port = '.$service->port,
            'data-dir = /var/lib/typesense', 'api-key = '.$service->secret, 'log-dir = /var/log/typesense', '',
        ]);

        return implode("\n", [
            'case "$(uname -m)" in aarch64|arm64) ARCH=arm64 ;; *) ARCH=amd64 ;; esac',
            "if ! dpkg -s typesense-server >/dev/null 2>&1; then curl -fsSL -o /tmp/typesense.deb \"https://dl.typesense.org/releases/{$version}/typesense-server-{$version}-\${ARCH}.deb\"; apt-get install -y -qq /tmp/typesense.deb; rm -f /tmp/typesense.deb; fi",
            $this->write('/etc/typesense/typesense-server.ini', $config, '0640'),
            'systemctl enable typesense-server >/dev/null',
            'systemctl restart typesense-server',
        ]);
    }

    /**
     * Install Redis with a password, listening on the given addresses.
     *
     * @param  ServerService  $service
     * @param  list<string>  $bind
     * @return string
     */
    private function redis(ServerService $service, array $bind): string
    {
        $settings = implode("\n", ['# Managed by BuildPusher.', 'bind '.implode(' ', $bind), 'port '.$service->port, 'requirepass '.$service->secret, 'protected-mode yes', '']);

        return implode("\n", [
            'dpkg -s redis-server >/dev/null 2>&1 || (apt-get update -qq && apt-get install -y -qq redis-server)',
            'install -d -m 0755 /etc/redis/conf.d',
            $this->write('/etc/redis/conf.d/buildpusher.conf', $settings, '0640'),
            'chown redis:redis /etc/redis/conf.d/buildpusher.conf',
            "grep -q '^include /etc/redis/conf.d/buildpusher.conf' /etc/redis/redis.conf || echo 'include /etc/redis/conf.d/buildpusher.conf' >> /etc/redis/redis.conf",
            'systemctl enable redis-server >/dev/null',
            'systemctl restart redis-server',
        ]);
    }

    /**
     * Get commands that write a file in one go, with the given permissions.
     *
     * @param  string  $path
     * @param  string  $contents
     * @param  string  $mode
     * @return string
     */
    private function write(string $path, string $contents, string $mode): string
    {
        return 'install -d '.escapeshellarg(dirname($path))."\n"
            .'echo '.escapeshellarg(base64_encode($contents)).' | base64 -d > '.escapeshellarg($path.'.new')
            .' && chmod '.$mode.' '.escapeshellarg($path.'.new').' && mv '.escapeshellarg($path.'.new').' '.escapeshellarg($path);
    }
}
