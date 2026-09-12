<?php

namespace App\Actions\Web;

use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ActivityRecorder;
use App\Services\PlanLimits;
use App\Services\Runner;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImportWebsiteAction
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly Runner $runner,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Verify an existing application directory and create an active website without provisioning it.
     *
     * @param  User  $user  Actor whose current workspace owns the website.
     * @param  Server  $server  Active server already resolved within the user's workspace.
     * @param  array{name: string, description: string, url: string, deployment_slug: string}  $attributes  Validated and normalized import details.
     * @return Website The imported active website.
     *
     * @throws ValidationException If the remote application directory cannot be read.
     */
    public function handle(User $user, Server $server, array $attributes): Website
    {
        $root = '/var/www/'.$attributes['deployment_slug'];
        $probe = $this->runner->server($server)->create()->execute('test -d '.escapeshellarg($root).' && test -r '.escapeshellarg($root));
        if (! $probe->isSuccessful()) {
            throw ValidationException::withMessages(['deployment_slug' => __('That readable application directory was not found under /var/www on this server.')]);
        }

        /** @var Website $website */
        $website = $this->limits->withinLimit($user, 'websites', fn ($organization): Website => $organization->websites()->create([
            'user_id' => $user->id,
            'server_id' => $server->id,
            'name' => $attributes['name'],
            'description' => $attributes['description'],
            'url' => $attributes['url'],
            'deployment_slug' => $attributes['deployment_slug'],
            'environment' => '',
            'database_password' => Str::password(32, symbols: false),
            'setup_stage' => 0,
            'provisioning_status' => Website::STATUS_ACTIVE,
            'provisioned_at' => now(),
            'health_check_enabled' => false,
            'health_status' => Website::HEALTH_UNKNOWN,
        ]));
        $this->activity->record($website, $user->id, 'website', 'Existing application imported without modifying its files or proxy configuration.');

        return $website;
    }
}
