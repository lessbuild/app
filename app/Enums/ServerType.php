<?php

declare(strict_types=1);

namespace App\Enums;

/** What a server is provisioned for, which decides the software it gets. */
enum ServerType: string
{
    case App = 'app';
    case Web = 'web';
    case Worker = 'worker';
    case Cache = 'cache';
    case Database = 'database';
    case LoadBalancer = 'load-balancer';

    /**
     * The server type's name as shown on server forms and lists.
     */
    public function label(): string
    {
        return match ($this) {
            self::App => __('App server'),
            self::Web => __('Web server'),
            self::Worker => __('Worker'),
            self::Cache => __('Cache'),
            self::Database => __('Database'),
            self::LoadBalancer => __('Load balancer'),
        };
    }

    /** Website provisioning creates a local MySQL database, so only full app servers host websites. */
    public function canHostWebsites(): bool
    {
        return $this === self::App;
    }

    /**
     * The software provisioning installs for this type of server. Also decides which servers can front a load balancer:
     * those with Caddy.
     *
     * @return list<string>
     */
    public function installs(): array
    {
        return match ($this) {
            self::App => ['php', 'composer', 'node', 'caddy', 'mysql', 'redis', 'memcached'],
            self::Web => ['php', 'composer', 'caddy'],
            self::Worker => ['php', 'composer', 'node'],
            self::Database => ['mysql'],
            self::Cache => ['redis', 'memcached'],
            self::LoadBalancer => ['caddy'],
        };
    }
}
