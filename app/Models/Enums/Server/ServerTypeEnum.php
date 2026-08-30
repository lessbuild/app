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

    public function canHostWebsites(): bool
    {
        return in_array($this, [self::app, self::web], true);
    }

    /**
     * @return list<string>
     */
    public static function websiteHostingValues(): array
    {
        return array_map(
            fn (self $type): string => $type->value,
            array_filter(self::cases(), fn (self $type): bool => $type->canHostWebsites()),
        );
    }

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
