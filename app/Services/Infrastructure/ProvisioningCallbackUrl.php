<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\Server;
use App\Models\Website;
use Illuminate\Support\Facades\URL;

/** Signed, expiring URLs a provisioning script calls to report progress, failure and its log. Each carries the attempt token. */
final class ProvisioningCallbackUrl
{
    public static function serverStatus(Server $server): string
    {
        return self::server('status', $server);
    }

    public static function serverFailure(Server $server): string
    {
        return self::server('failed', $server);
    }

    public static function serverLog(Server $server): string
    {
        return self::server('log', $server);
    }

    public static function websiteStatus(Website $website): string
    {
        return self::website('status', $website);
    }

    public static function websiteFailure(Website $website): string
    {
        return self::website('failed', $website);
    }

    public static function websiteLog(Website $website): string
    {
        return self::website('log', $website);
    }

    private static function website(string $event, Website $website): string
    {
        return URL::temporarySignedRoute(
            'callbacks.website',
            now()->addMinutes(max(1, (int) config('infrastructure.website_callback_ttl_minutes'))),
            ['website' => $website->id, 'event' => $event, 'attempt' => $website->provisioning_token],
        );
    }

    private static function server(string $event, Server $server): string
    {
        return URL::temporarySignedRoute(
            'callbacks.server',
            now()->addMinutes(max(1, (int) config('infrastructure.server_callback_ttl_minutes'))),
            ['server' => $server->id, 'event' => $event, 'attempt' => $server->provisioning_token],
        );
    }
}
