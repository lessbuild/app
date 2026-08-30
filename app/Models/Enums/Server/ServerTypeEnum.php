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
                'composer',
                'node',
                'caddy',
                'mysql',
                'redis',
                'memcached',
            ],
            self::web => [
                'php',
                'composer',
                'caddy',
            ],
            self::worker => [
                'php',
                'composer',
                'node',
            ],
            self::database => [
                'mysql',
            ],
            self::cache => [
                'redis',
                'memcached',
            ],
            self::loadbalancer => [
                'caddy',
            ],
        };
    }
}
