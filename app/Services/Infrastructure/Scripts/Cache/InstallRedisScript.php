<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Scripts\Cache;

use App\Contracts\Infrastructure\ServerScript;
use App\Models\Server;

class InstallRedisScript implements ServerScript
{
    public const TITLE = 'Install Redis';

    public const DESCRIPTION = 'Install Redis and configure Redis';

    public const IDENTIFIER = 'installed-redis';

    /**
     * Shell script to run
     */
    public function script(int $step, Server $server): string
    {
        $phpVersion = (string) config('infrastructure.default_php_version', '8.4');

        return <<<SCRIPT
        provisionPing {$server->id} {$step}

        # Install Redis
        apt_wait
        sudo env DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confold install -y redis-server

        # Configure Redis
        backupManagedFile /etc/redis/redis.conf
        sed -i -E 's/^bind .*/bind 127.0.0.1 ::1/' /etc/redis/redis.conf
        sed -i -E 's/^#? *protected-mode .*/protected-mode yes/' /etc/redis/redis.conf
        service redis-server restart
        systemctl enable redis-server

        # Use the distribution package rather than compiling an unpinned PECL release.
        if [ -d /etc/php/{$phpVersion} ]; then
            apt_wait
            sudo env DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confold install -y php{$phpVersion}-redis
        fi
        SCRIPT;
    }
}
