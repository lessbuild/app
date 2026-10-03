<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Models\Account;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Http\Request;

/**
 * The resources API's JSON for projects, servers, websites and monitors (the shapes the Terraform provider reads),
 * and the token's account. Secrets never appear.
 */
final class ResourceJson
{
    /**
     * Get the account the request's token belongs to.
     *
     * @param  Request  $request
     * @return Account
     */
    public static function account(Request $request): Account
    {
        $account = $request->attributes->get('account');
        abort_unless($account instanceof Account, 403);

        return $account;
    }

    /**
     * Describe a project.
     *
     * @param  Project  $project
     * @return array<string, mixed>
     */
    public static function project(Project $project): array
    {
        $project->loadMissing(['enabledServices', 'environments']);

        return [
            'id' => $project->id, 'name' => $project->name, 'slug' => $project->slug, 'description' => $project->description,
            'services' => $project->enabledServices->pluck('service')->sort()->values()->all(),
            'environments' => $project->environments->map(fn ($environment): array => ['id' => $environment->id, 'name' => $environment->name, 'slug' => $environment->slug])->values()->all(),
            'created_at' => $project->created_at?->toIso8601String(),
        ];
    }

    /**
     * Describe a server.
     *
     * @param  Server  $server
     * @return array<string, mixed>
     */
    public static function server(Server $server): array
    {
        return [
            'id' => $server->id, 'name' => $server->name, 'provider_id' => $server->provider_id, 'type' => $server->type->value,
            'database_engine' => $server->database_engine, 'region' => $server->region, 'size' => $server->size, 'image' => $server->image,
            'public_ip' => $server->public_ip, 'private_ip' => $server->private_ip, 'status' => $server->provisioning_status,
            'error' => $server->provisioning_error, 'monthly_cost' => $server->monthly_cost, 'monthly_cost_currency' => $server->monthly_cost_currency,
            'created_at' => $server->created_at?->toIso8601String(),
        ];
    }

    /**
     * Describe a website.
     *
     * @param  Website  $website
     * @return array<string, mixed>
     */
    public static function website(Website $website): array
    {
        return [
            'id' => $website->id, 'name' => $website->name, 'url' => $website->url, 'description' => $website->description,
            'server_id' => $website->server_id, 'environment_id' => $website->environment_id, 'status' => $website->provisioning_status,
            'error' => $website->provisioning_error, 'release_retention' => $website->release_retention,
            'health_check_enabled' => $website->health_check_enabled, 'health_check_path' => $website->health_check_path,
            'health_monitoring_enabled' => $website->health_monitoring_enabled, 'self_healing' => $website->self_healing,
            'health_status' => $website->health_status, 'created_at' => $website->created_at?->toIso8601String(),
        ];
    }

    /**
     * Describe a monitor, with the version an update or delete sends back.
     *
     * @param  Monitor  $monitor
     * @return array<string, mixed>
     */
    public static function monitor(Monitor $monitor): array
    {
        return [
            'id' => $monitor->id, 'name' => $monitor->name, 'environment_id' => $monitor->environment_id, 'check_type' => $monitor->type,
            'request_url' => $monitor->request_url, 'method' => $monitor->method, 'status_min' => $monitor->status_min, 'status_max' => $monitor->status_max,
            'body_contains' => $monitor->body_contains, 'max_duration_ms' => $monitor->max_duration_ms, 'hostname' => $monitor->hostname,
            'timeout_seconds' => $monitor->timeout_seconds, 'interval_minutes' => $monitor->interval_minutes,
            'trigger_checks' => $monitor->trigger_checks, 'recovery_checks' => $monitor->recovery_checks, 'enabled' => $monitor->enabled,
            'health' => $monitor->health, 'checked_at' => $monitor->checked_at?->toIso8601String(), 'version' => $monitor->state_version,
        ];
    }
}
