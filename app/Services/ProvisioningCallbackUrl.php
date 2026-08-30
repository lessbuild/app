<?php

namespace App\Services;

use App\Models\Build;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Support\Facades\URL;

class ProvisioningCallbackUrl
{
    public static function serverStatus(Server $server): string
    {
        return self::temporary('callbacks.server', [
            'server' => $server,
            'attempt' => $server->provisioning_token,
        ], 'server_callback_ttl_minutes');
    }

    public static function serverFailure(Server $server): string
    {
        return self::temporary('callbacks.server.failed', [
            'server' => $server,
            'attempt' => $server->provisioning_token,
        ], 'server_callback_ttl_minutes');
    }

    public static function serverLog(Server $server): string
    {
        return self::temporary('callbacks.server.log', [
            'server' => $server,
            'attempt' => $server->provisioning_token,
        ], 'server_callback_ttl_minutes');
    }

    public static function websiteStatus(Website $website): string
    {
        return self::temporary('callbacks.website', [
            'website' => $website,
            'attempt' => $website->provisioning_token,
        ], 'deployment_callback_ttl_minutes');
    }

    public static function websiteFailure(Website $website): string
    {
        return self::temporary('callbacks.website.failed', [
            'website' => $website,
            'attempt' => $website->provisioning_token,
        ], 'deployment_callback_ttl_minutes');
    }

    public static function websiteLog(Website $website): string
    {
        return self::temporary('callbacks.website.log', [
            'website' => $website,
            'attempt' => $website->provisioning_token,
        ], 'deployment_callback_ttl_minutes');
    }

    public static function buildStatus(Build $build): string
    {
        return self::temporary('callbacks.build.status', $build, 'deployment_callback_ttl_minutes');
    }

    public static function buildFailure(Build $build): string
    {
        return self::temporary('callbacks.build.failed', $build, 'deployment_callback_ttl_minutes');
    }

    public static function buildLog(Build $build): string
    {
        return self::temporary('callbacks.build.log', $build, 'deployment_callback_ttl_minutes');
    }

    public static function buildRevision(Build $build): string
    {
        return self::temporary('callbacks.build.revision', $build, 'deployment_callback_ttl_minutes');
    }

    private static function temporary(string $route, mixed $parameter, string $ttlConfig): string
    {
        return URL::temporarySignedRoute(
            $route,
            now()->addMinutes(max(1, (int) config("lessbuild.{$ttlConfig}"))),
            $parameter,
        );
    }
}
