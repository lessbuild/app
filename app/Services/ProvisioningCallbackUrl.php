<?php

namespace App\Services;

use App\Models\Build;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Support\Facades\URL;

class ProvisioningCallbackUrl
{
    /**
     * Sign an expiring server provisioning progress callback URL.
     *
     * @param  Server  $server  The server supplying the route identity and current provisioning-attempt token.
     * @return string The signed progress URL using the configured server callback lifetime.
     */
    public static function serverStatus(Server $server): string
    {
        return self::temporary('callbacks.server', [
            'server' => $server,
            'attempt' => $server->provisioning_token,
        ], 'server_callback_ttl_minutes');
    }

    /**
     * Sign an expiring server provisioning failure callback URL.
     *
     * @param  Server  $server  The server supplying the route identity and current provisioning-attempt token.
     * @return string The signed failure URL using the configured server callback lifetime.
     */
    public static function serverFailure(Server $server): string
    {
        return self::temporary('callbacks.server.failed', [
            'server' => $server,
            'attempt' => $server->provisioning_token,
        ], 'server_callback_ttl_minutes');
    }

    /**
     * Sign an expiring server provisioning log upload callback URL.
     *
     * @param  Server  $server  The server supplying the route identity and current provisioning-attempt token.
     * @return string The signed log upload URL using the configured server callback lifetime.
     */
    public static function serverLog(Server $server): string
    {
        return self::temporary('callbacks.server.log', [
            'server' => $server,
            'attempt' => $server->provisioning_token,
        ], 'server_callback_ttl_minutes');
    }

    /**
     * Sign an expiring website provisioning progress callback URL.
     *
     * @param  Website  $website  The website supplying the route identity and current provisioning-attempt token.
     * @return string The signed progress URL using the configured deployment callback lifetime.
     */
    public static function websiteStatus(Website $website): string
    {
        return self::temporary('callbacks.website', [
            'website' => $website,
            'attempt' => $website->provisioning_token,
        ], 'deployment_callback_ttl_minutes');
    }

    /**
     * Sign an expiring website provisioning failure callback URL.
     *
     * @param  Website  $website  The website supplying the route identity and current provisioning-attempt token.
     * @return string The signed failure URL using the configured deployment callback lifetime.
     */
    public static function websiteFailure(Website $website): string
    {
        return self::temporary('callbacks.website.failed', [
            'website' => $website,
            'attempt' => $website->provisioning_token,
        ], 'deployment_callback_ttl_minutes');
    }

    /**
     * Sign an expiring website provisioning log upload callback URL.
     *
     * @param  Website  $website  The website supplying the route identity and current provisioning-attempt token.
     * @return string The signed log upload URL using the configured deployment callback lifetime.
     */
    public static function websiteLog(Website $website): string
    {
        return self::temporary('callbacks.website.log', [
            'website' => $website,
            'attempt' => $website->provisioning_token,
        ], 'deployment_callback_ttl_minutes');
    }

    /**
     * Sign an expiring deployment progress callback URL.
     *
     * @param  Build  $build  The build supplying the route identity.
     * @return string The signed progress URL using the configured deployment callback lifetime.
     */
    public static function buildStatus(Build $build): string
    {
        return self::temporary('callbacks.build.status', $build, 'deployment_callback_ttl_minutes');
    }

    /**
     * Sign an expiring deployment failure callback URL.
     *
     * @param  Build  $build  The build supplying the route identity.
     * @return string The signed failure URL using the configured deployment callback lifetime.
     */
    public static function buildFailure(Build $build): string
    {
        return self::temporary('callbacks.build.failed', $build, 'deployment_callback_ttl_minutes');
    }

    /**
     * Sign an expiring deployment log upload callback URL.
     *
     * @param  Build  $build  The build supplying the route identity.
     * @return string The signed log upload URL using the configured deployment callback lifetime.
     */
    public static function buildLog(Build $build): string
    {
        return self::temporary('callbacks.build.log', $build, 'deployment_callback_ttl_minutes');
    }

    /**
     * Sign an expiring deployment resolved revision callback URL.
     *
     * @param  Build  $build  The build supplying the route identity.
     * @return string The signed resolved revision URL using the configured deployment callback lifetime.
     */
    public static function buildRevision(Build $build): string
    {
        return self::temporary('callbacks.build.revision', $build, 'deployment_callback_ttl_minutes');
    }

    /**
     * Sign a callback route with a configured lifetime of at least one minute.
     *
     * @param  string  $route  The named callback route to sign.
     * @param  mixed  $parameter  The model or parameter map used to populate the route.
     * @param  string  $ttlConfig  The lessbuild configuration key specifying the lifetime in minutes.
     * @return string The absolute temporary signed route URL.
     */
    private static function temporary(string $route, mixed $parameter, string $ttlConfig): string
    {
        return URL::temporarySignedRoute(
            $route,
            now()->addMinutes(max(1, (int) config("lessbuild.{$ttlConfig}"))),
            $parameter,
        );
    }
}
