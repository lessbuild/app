<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\CustomerStatusPageProvider;
use App\Core\Data\Status\CustomerStatusPage as CoreCustomerStatusPage;
use App\Modules\Deployer\Actions\Status\SubscribeToStatusPageAction;
use App\Modules\Deployer\Models\StatusIncident;
use App\Modules\Deployer\Models\StatusPage;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Presenters\StatusComponentPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;

/** Supplies the public status view from Deployer-owned records and subscription actions. */
final class DeployerCustomerStatusPageProvider implements CustomerStatusPageProvider
{
    public function __construct(
        private readonly StatusComponentPresenter $components,
        private readonly SubscribeToStatusPageAction $subscriptions,
    ) {}

    public function findPublished(string $slug): ?CoreCustomerStatusPage
    {
        if (! config('platform.products.deployer.enabled', false)) {
            return null;
        }

        try {
            return $this->publishedPage($slug);
        } catch (LostConnectionException|QueryException) {
            return null;
        }
    }

    private function publishedPage(string $slug): ?CoreCustomerStatusPage
    {
        $since = now()->subDays(30);
        $page = StatusPage::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->with([
                'organization:id,name',
                'websites' => fn ($query) => $query->withCount([
                    'healthChecks as recent_health_checks_count' => fn (Builder $checks) => $checks->where('checked_at', '>=', $since),
                    'healthChecks as successful_recent_health_checks_count' => fn (Builder $checks) => $checks
                        ->where('checked_at', '>=', $since)
                        ->where('successful', true),
                ]),
            ])
            ->first();

        if (! $page instanceof StatusPage || $page->organization === null) {
            return null;
        }

        $components = $page->websites
            ->map(function (Website $website): array {
                $component = $this->components->present($website);
                $operational = $component['operational'];

                return [
                    'name' => $component['name'],
                    'type' => __('Website'),
                    'state' => $operational ? 'operational' : 'degraded',
                    'stateLabel' => __($component['status']),
                    'checkedAt' => $component['checked_at'] === null ? null : CarbonImmutable::parse($component['checked_at']),
                    'history' => null,
                    'uptime' => $component['uptime_30d'],
                ];
            })
            ->values();
        $overall = $components->contains(fn (array $component): bool => $component['state'] !== 'operational')
            ? 'degraded'
            : 'operational';

        $activeIncidents = $page->incidents()
            ->whereNotIn('status', ['resolved', 'completed'])
            ->latest('starts_at')
            ->limit(20)
            ->get()
            ->map(fn (StatusIncident $incident): object => $this->incident($incident));
        $recentIncidents = $page->incidents()
            ->whereIn('status', ['resolved', 'completed'])
            ->latest('starts_at')
            ->limit(20)
            ->get()
            ->map(fn (StatusIncident $incident): object => $this->incident($incident));

        return new CoreCustomerStatusPage(
            product: 'deployer',
            slug: (string) $page->slug,
            name: (string) $page->name,
            workspaceName: (string) $page->organization->name,
            description: $page->description,
            overall: $overall,
            overallLabel: $overall === 'operational' ? __('All systems operational') : __('Some systems are degraded'),
            components: $components,
            incidents: $activeIncidents,
            recentIncidents: $recentIncidents,
        );
    }

    public function subscribe(string $slug, string $email): bool
    {
        if (! config('platform.products.deployer.enabled', false)) {
            return false;
        }

        try {
            $page = StatusPage::query()
                ->where('slug', $slug)
                ->where('is_published', true)
                ->first();
            if (! $page instanceof StatusPage) {
                return false;
            }

            $this->subscriptions->handle($page, $email);

            return true;
        } catch (LostConnectionException|QueryException) {
            return false;
        }
    }

    private function incident(StatusIncident $incident): object
    {
        return (object) [
            'kind' => (string) $incident->kind,
            'severity' => (string) $incident->severity,
            'title' => (string) $incident->title,
            'status' => (string) $incident->status,
            'message' => (string) $incident->message,
            'opened_at' => $incident->starts_at,
            'resolved_at' => $incident->resolved_at ?? $incident->ends_at,
        ];
    }
}
