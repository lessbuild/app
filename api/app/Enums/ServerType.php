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
     * Get the server type's name as shown on server forms and lists.
     *
     * @return string
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

    /**
     * Determine whether servers of this type can host websites. Website provisioning creates a local MySQL database,
     * so only full app servers can.
     *
     * @return bool
     */
    public function canHostWebsites(): bool
    {
        return $this === self::App;
    }

    /**
     * Get the software provisioning installs for this type of server. Also decides which servers can front a load
     * balancer: those with Caddy.
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
