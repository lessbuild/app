<?php

namespace App\Models\Enums\Server;

enum ServerTypeEnum: string
{
    case app = 'app';
    case web = 'web';
    case worker = 'worker';
    case cache = 'cache';
    case database = 'database';
    case loadbalancer = 'load-balancer';

    public function installs(): array
    {
        return match ($this) {
            self::app => [
                'php',
                'nginx',
                'database',
                'redis',
                'memcached',
            ],
            self::web => [
                'php',
                'nginx',
            ],
            self::worker => [
                'php',
            ],
            self::database => [
                'mysql',
                'psql',
            ],
            self::cache => [
                'redis',
                'memcached',
            ],
            self::loadbalancer => [
                'nginx',
            ],
        };
    }
}
