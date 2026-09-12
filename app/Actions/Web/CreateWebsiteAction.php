<?php

namespace App\Actions\Web;

use App\Data\WebsiteCreationResult;
use App\Jobs\Web\AddWebsiteJob;
use App\Models\User;
use App\Models\Website;
use App\Services\Entitlements;
use App\Services\PlanLimits;
use Illuminate\Support\Str;

class CreateWebsiteAction
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly PlanLimits $limits,
    ) {}

    /**
     * Create a queued website within workspace limits and dispatch its provisioning attempt.
     *
     * @param  User  $user  Actor whose current workspace owns the website.
     * @param  array<string, mixed>  $attributes  Validated and normalized website settings.
     * @return WebsiteCreationResult The queued website and one-time plaintext database password.
     */
    public function handle(User $user, array $attributes): WebsiteCreationResult
    {
        if ($attributes['health_monitoring_enabled']) {
            $this->entitlements->enforce($user->currentOrganization, 'monitoring');
        }

        $password = Str::random(32);
        $website = $this->limits->withinLimit(
            $user,
            'websites',
            fn ($organization): Website => $organization->websites()->create(array_merge($attributes, [
                'user_id' => $user->id,
                'database_password' => $password,
                'provisioning_status' => Website::STATUS_QUEUED,
            ])),
        );
        /** @var Website $website */
        AddWebsiteJob::dispatch($website);

        return new WebsiteCreationResult($website, $password);
    }
}
