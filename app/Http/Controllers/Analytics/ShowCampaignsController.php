<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

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
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowCampaignsController
{
    /**
     * Show the campaign link builder (building the link from the query string when a URL is given) and how the site's
     * tagged campaigns did over the last 30 days, with the ad spend imported for them.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectSitesQuery  $sites
     * @param  CampaignResultsQuery  $campaigns
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSitesQuery $sites, CampaignResultsQuery $campaigns): View
    {
        $site = $sites->selected($project, $request->query('site'));
        $parameters = [];
        foreach (array_keys(CampaignLink::PARAMETERS) as $key) {
            $value = $request->query($key);
            $parameters[$key] = is_string($value) ? $value : null;
        }
        $url = $request->query('url');
        $link = is_string($url) && $url !== '' ? CampaignLink::build($url, $parameters) : null;

        return view('analytics.campaigns', [
            'overview' => $overview->handle($project, $user),
            'sites' => $sites->handle($project),
            'site' => $site,
            'url' => is_string($url) ? $url : ($site !== null && ($site->domains[0] ?? null) !== null ? 'https://'.$site->domains[0].'/' : ''),
            'parameters' => $parameters,
            'link' => $link,
            'invalid' => is_string($url) && $url !== '' && $link === null,
            'results' => $site !== null ? $campaigns->handle($site) : [],
            'canManage' => $site !== null && $user->can('update', $site),
            'adAccounts' => $site === null ? collect() : AnalyticsAdAccount::query()->where('site_id', $site->id)->orderBy('name')->get(),
            'adPlatforms' => app(AdPlatforms::class)->configured(),
            // After signing in to Google or Meta: the ad accounts to choose from, for this site only.
            'adChoice' => $site !== null && is_array($choice = $request->session()->get('ads.choose')) && ($choice['site'] ?? null) === $site->id ? $choice : null,
            // What spend has been imported, per source: days, total rows and the dates covered.
            'spend' => $site === null ? collect() : AnalyticsAdSpend::query()->where('site_id', $site->id)->toBase()
                ->selectRaw('source, COUNT(*) AS days, MIN(date) AS first_date, MAX(date) AS last_date')->groupBy('source')->orderBy('source')->get(),
        ]);
    }
}
