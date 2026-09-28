<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\Build;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Support\Facades\URL;

/** Signed, expiring URLs a provisioning script calls to report progress, failure and its log. Each carries the attempt token. */
final class ProvisioningCallbackUrl
{
    /**
     * The URL a server's provisioning script reports finished stages to.
     */
    public static function serverStatus(Server $server): string
    {
        return self::server('status', $server);
    }

    /**
     * The URL it reports failure to.
     */
    public static function serverFailure(Server $server): string
    {
        return self::server('failed', $server);
    }

    /**
     * The URL it uploads its log to.
     */
    public static function serverLog(Server $server): string
    {
        return self::server('log', $server);
    }

    /**
     * The URL a website's setup script reports finished stages to.
     */
    public static function websiteStatus(Website $website): string
    {
        return self::website('status', $website);
    }

    /**
     * The URL it reports failure to.
     */
    public static function websiteFailure(Website $website): string
    {
        return self::website('failed', $website);
    }

    /**
     * The URL it uploads its log to.
     */
    public static function websiteLog(Website $website): string
    {
        return self::website('log', $website);
    }

    /** Deployment callbacks keep Deployer's URLs (`/builds/{build}/deployment/callback/{event}`), a public contract. */
    public static function buildStatus(Build $build): string
    {
        return self::build('callbacks.build.status', $build);
    }

    /**
     * The URL a deploy script reports failure to.
     */
    public static function buildFailure(Build $build): string
    {
        return self::build('callbacks.build.failed', $build);
    }

    /**
     * The URL it uploads its log to.
     */
    public static function buildLog(Build $build): string
    {
        return self::build('callbacks.build.log', $build);
    }

    /**
     * The URL it reports the deployed commit to.
     */
    public static function buildRevision(Build $build): string
    {
        return self::build('callbacks.build.revision', $build);
    }

    /**
     * A signed URL for a deploy callback route, expiring after the configured time.
     */
    private static function build(string $route, Build $build): string
    {
        return URL::temporarySignedRoute($route, now()->addMinutes(max(1, (int) config('deploy.callback_ttl_minutes'))), ['build' => $build->id]);
    }

    /**
     * A signed URL for a website callback, carrying its provisioning attempt so reports from an earlier attempt are
     * ignored.
     */
    private static function website(string $event, Website $website): string
    {
        return URL::temporarySignedRoute(
            'callbacks.website',
            now()->addMinutes(max(1, (int) config('infrastructure.website_callback_ttl_minutes'))),
            ['websiteId' => $website->id, 'event' => $event, 'attempt' => $website->provisioning_token],
        );
    }

    /**
     * A signed URL for a server callback, carrying its provisioning attempt so reports from an earlier attempt are
     * ignored.
     */
    private static function server(string $event, Server $server): string
    {
        return URL::temporarySignedRoute(
            'callbacks.server',
            now()->addMinutes(max(1, (int) config('infrastructure.server_callback_ttl_minutes'))),
            ['serverId' => $server->id, 'event' => $event, 'attempt' => $server->provisioning_token],
        );
    }
}
