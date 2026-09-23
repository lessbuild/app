<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProjectSetupProvider;
use App\Core\Data\Projects\ProjectSetupStep;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Modules\Analytics\Models\AnalyticsEvent;
use Illuminate\Support\Facades\Route;

final class AnalyticsProjectSetup implements ProjectSetupProvider
{
    public function __construct(private readonly AnalyticsProjectLink $sites) {}

    public function steps(PlatformUser $user, Project $project): array
    {
        $authorizedSites = $this->sites->accessibleSites($user, $project);
        $siteIds = $authorizedSites->modelKeys();
        $site = $authorizedSites->first();
        $dashboardUrl = $site !== null && Route::has('analytics.dashboard')
            ? route('analytics.dashboard', ['site' => $site->getKey()])
            : (Route::has('analytics.dashboard') ? route('analytics.dashboard') : null);

        if ($authorizedSites->isEmpty()) {
            return [new ProjectSetupStep(
                id: 'analytics.site',
                product: 'analytics',
                title: __('Connect an Analytics site'),
                detail: __('Open Analytics to add a site or select an existing one. Linked sites are reused without creating duplicates.'),
                state: ProjectSetupStepState::NeedsAction,
                url: Route::has('analytics.sites.create') ? route('analytics.sites.create') : $dashboardUrl,
                actionLabel: __('Open Analytics'),
            )];
        }

        $unverified = $authorizedSites->filter(fn ($site): bool => $site->verified_at === null)->count();
        $hasReceivedEvent = AnalyticsEvent::query()->whereIn('site_id', $siteIds)->exists();

        return [
            new ProjectSetupStep(
                id: 'analytics.site',
                product: 'analytics',
                title: __('Verify an Analytics site'),
                detail: $unverified === 0
                    ? trans_choice(':count linked site is verified.|:count linked sites are verified.', $authorizedSites->count(), ['count' => $authorizedSites->count()])
                    : trans_choice(':count linked site still needs verification.|:count linked sites still need verification.', $unverified, ['count' => $unverified]),
                state: $unverified === 0 ? ProjectSetupStepState::Complete : ProjectSetupStepState::NeedsAction,
                url: $site !== null && Route::has('analytics.sites.setup')
                    ? route('analytics.sites.setup', $site->getKey())
                    : $dashboardUrl,
                actionLabel: $unverified > 0 ? __('Review site setup') : null,
            ),
            new ProjectSetupStep(
                id: 'analytics.event',
                product: 'analytics',
                title: __('Receive a tracker event'),
                detail: $hasReceivedEvent
                    ? __('Analytics has received a tracker event for a linked site.')
                    : __('Install the tracker on a linked site and send its first event.'),
                state: $hasReceivedEvent ? ProjectSetupStepState::Complete : ProjectSetupStepState::NeedsAction,
                url: $dashboardUrl,
                actionLabel: $hasReceivedEvent ? null : __('Open Analytics'),
            ),
        ];
    }
}
