<?php

namespace App\Scripts\Cache;

use App\Contracts\Scripts\ServerScript;
use App\Models\Server;

class InstallRedisScript implements ServerScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Install Redis';

    /**
     * Description of the script
     */
    public static string $description = 'Install Redis and configure Redis';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'installed-redis';

    /**
     * Shell script to run
     */
    public function script(int $step, Server $server): string
    {
        $phpVersion = (string) config('lessbuild.default_php_version', '8.4');
        return <<<SCRIPT
        provisionPing {$server->id} {$step}

        # Install Redis
        apt_wait
        yes | sudo apt install redis-server

        # Configure Redis
        backupManagedFile /etc/redis/redis.conf
        sed -i -E 's/^bind .*/bind 127.0.0.1 ::1/' /etc/redis/redis.conf
        sed -i -E 's/^#? *protected-mode .*/protected-mode yes/' /etc/redis/redis.conf
        service redis-server restart
        systemctl enable redis-server

        # Use the distribution package rather than compiling an unpinned PECL release.
        if [ -d /etc/php/{$phpVersion} ]; then
            apt_wait
            yes | sudo apt install php{$phpVersion}-redis
        fi
        SCRIPT;
    }
}
