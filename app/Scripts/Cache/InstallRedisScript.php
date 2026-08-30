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
        return <<<SCRIPT
        provisionPing {$server->id} {$step}

        # Install Redis
        apt_wait
        yes | sudo apt install redis-server
        yes '' | pecl install -f redis

        # Configure Redis
        sed -i 's/bind 127.0.0.1/bind 0.0.0.0/' /etc/redis/redis.conf
        service redis-server restart
        systemctl enable redis-server

        # Ensure PHPRedis extension is available
        if pecl list | grep redis >/dev/null 2>&1; then
            echo "Configuring PHPRedis"
            echo "extension=redis.so" > /etc/php/8.1/mods-available/redis.ini
            apt_wait
            yes | sudo apt install php8.1-redis
        fi
        SCRIPT;
    }
}
