<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProjectSetupProvider;
use App\Core\Data\Projects\ProjectSetupStep;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\Site;
use Illuminate\Support\Facades\Route;

final class AnalyticsProjectSetup implements ProjectSetupProvider
{
    public function __construct(private readonly AnalyticsProjectLink $sites) {}

    public function steps(PlatformUser $user, Project $project): array
    {
        $authorizedSites = $this->sites->accessibleSites($user, $project);
        $siteIds = $authorizedSites->modelKeys();
        $dashboardUrl = Route::has('analytics.dashboard') ? route('analytics.dashboard') : null;

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

        $sitesWithEvents = AnalyticsEvent::query()
            ->whereIn('site_id', $siteIds)
            ->distinct()
            ->pluck('site_id')
            ->mapWithKeys(static fn (int|string $siteId): array => [(string) $siteId => true]);

        return $authorizedSites
            ->flatMap(function (Site $site) use ($sitesWithEvents, $dashboardUrl): array {
                $verified = $site->verified_at !== null;
                $setupUrl = Route::has('analytics.sites.setup')
                    ? route('analytics.sites.setup', $site->getKey())
                    : $dashboardUrl;
                $hasReceivedEvent = $site->last_event_at !== null
                    || $sitesWithEvents->has((string) $site->getKey());
                $siteId = (string) $site->getKey();

                return [
                    new ProjectSetupStep(
                        id: 'analytics.site.'.$siteId,
                        product: 'analytics',
                        title: __('Verify an Analytics site'),
                        detail: $verified
                            ? __('This Analytics site is verified.')
                            : __('Verify this Analytics site before collecting events.'),
                        state: $verified ? ProjectSetupStepState::Complete : ProjectSetupStepState::NeedsAction,
                        url: $verified ? null : $setupUrl,
                        actionLabel: $verified || $setupUrl === null ? null : __('Review site setup'),
                        contextName: $site->name,
                        contextLabel: __('Site'),
                    ),
                    new ProjectSetupStep(
                        id: 'analytics.event.'.$siteId,
                        product: 'analytics',
                        title: __('Receive a tracker event'),
                        detail: $hasReceivedEvent
                            ? __('Analytics has received a tracker event for this site.')
                            : __('Install the tracker on this site and send its first event.'),
                        state: $hasReceivedEvent ? ProjectSetupStepState::Complete : ProjectSetupStepState::NeedsAction,
                        url: $hasReceivedEvent ? null : $setupUrl,
                        actionLabel: $hasReceivedEvent || $setupUrl === null ? null : __('Open site setup'),
                        contextName: $site->name,
                        contextLabel: __('Site'),
                    ),
                ];
            })
            ->values()
            ->all();
    }
}
