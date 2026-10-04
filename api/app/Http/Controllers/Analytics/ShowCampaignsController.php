<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Data\Analytics\SiteRow;
use App\Models\AnalyticsAdAccount;
use App\Models\AnalyticsAdSpend;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\CampaignResultsQuery;
use App\Queries\Analytics\ProjectSitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Analytics\AdPlatforms;
use App\Support\CampaignLink;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowCampaignsController
{
    /**
     * Show a site's campaigns (the one chosen with ?site=, else the first): the link builder (the link for ?url= and
     * the utm_* parameters), the last 30 days' results with their cost, and where ad spend comes from: imported files,
     * connected ad accounts, and, after signing in to an ad platform, the accounts to choose from.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectSitesQuery  $sites
     * @param  CampaignResultsQuery  $campaigns
     * @param  AdPlatforms  $platforms
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSitesQuery $sites, CampaignResultsQuery $campaigns, AdPlatforms $platforms): JsonResponse
    {
        $site = $sites->selected($project, $request->query('site'));
        $parameters = [];
        foreach (array_keys(CampaignLink::PARAMETERS) as $key) {
            $value = $request->query($key);
            $parameters[$key] = is_string($value) ? $value : null;
        }
        $url = $request->query('url');
        $link = is_string($url) && $url !== '' ? CampaignLink::build($url, $parameters) : null;
        $choice = $request->session()->get('ads.choose');
        $canManage = $site !== null && $user->can('update', $site);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'sites' => array_map(SiteRow::from(...), $sites->handle($project)),
            'site' => $site === null ? null : SiteRow::from($site),
            'builder' => [
                'url' => is_string($url) ? $url : ($site !== null && ($site->domains[0] ?? null) !== null ? 'https://'.$site->domains[0].'/' : ''),
                'parameters' => collect(CampaignLink::PARAMETERS)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => __($label), 'value' => $parameters[$key]])->values(),
                'link' => $link,
                'invalid' => is_string($url) && $url !== '' && $link === null,
            ],
            'results' => $site === null ? [] : array_map(fn (array $row): array => [
                'campaign' => $row['campaign'],
                'source' => $row['source'],
                'medium' => $row['medium'],
                'visits' => $row['visits'],
                'pageviews' => $row['pageviews'],
                'converted' => $row['converted'],
                'rate' => $row['rate'],
                'revenue' => $row['revenue'],
                'cost' => $row['cost'],
                'costPerConversion' => $row['cost_per_conversion'],
                'roas' => $row['roas'],
            ], $campaigns->handle($site)),
            // What spend has been imported, per source: the days covered and the first and last of them.
            'spend' => $site === null ? [] : AnalyticsAdSpend::query()->where('site_id', $site->id)->toBase()
                ->selectRaw('source, COUNT(*) AS days, MIN(date) AS first_date, MAX(date) AS last_date')->groupBy('source')->orderBy('source')->get()
                ->map(fn (object $row): array => ['source' => (string) $row->source, 'days' => (int) $row->days, 'from' => substr((string) $row->first_date, 0, 10), 'until' => substr((string) $row->last_date, 0, 10)])->values(),
            'adAccounts' => $site === null ? [] : AnalyticsAdAccount::query()->where('site_id', $site->id)->orderBy('name')->get()->map(fn (AnalyticsAdAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'platform' => $account->platformName(),
                'source' => $account->source,
                'syncedAt' => $account->synced_at?->toIso8601String(),
                'error' => $account->error,
            ])->values(),
            'adPlatforms' => $canManage ? array_map(fn (string $platform): array => ['key' => $platform, 'name' => AnalyticsAdAccount::PLATFORMS[$platform]['name']], $platforms->configured()) : [],
            // After signing in to Google or Meta: the ad accounts to choose from, for this site only.
            'adChoice' => $canManage && is_array($choice) && ($choice['site'] ?? null) === $site->id
                ? array_map(fn (array $account): array => ['value' => (string) $account['id'], 'label' => $account['name'].($account['currency'] ? ' · '.$account['currency'] : '')], $choice['accounts'] ?? [])
                : null,
            'canManage' => $canManage,
        ]);
    }
}
