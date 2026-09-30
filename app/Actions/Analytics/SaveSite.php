<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Data\Analytics\SiteDetails;
use App\Exceptions\AnalyticsRuleViolation;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Support\Hostname;
use Illuminate\Support\Facades\Gate;

final class SaveSite
{
    /**
     * Create a new SaveSite instance.
     *
     * Creates or changes an analytics site.
     *
     * @param  VerifySite  $verify  Verifies it once its domains are saved.
     * @param  Entitlements  $entitlements  Checks the plan's site limit.
     */
    public function __construct(private readonly VerifySite $verify, private readonly Entitlements $entitlements) {}

    /**
     * Create a site in the project, or update one. A new site counts against the account's `analytics.sites.max`. New
     * sites are verified straight away when a domain is already verified in the project.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  SiteDetails  $details
     * @param  AnalyticsSite|null  $site
     * @return AnalyticsSite
     */
    public function handle(User $actor, Project $project, SiteDetails $details, ?AnalyticsSite $site = null): AnalyticsSite
    {
        Gate::forUser($actor)->authorize($site === null ? 'create' : 'update', $site ?? [AnalyticsSite::class, $project]);

        $domains = [];
        foreach ($details->domains as $domain) {
            $domains[] = Hostname::normalize($domain) ?? throw AnalyticsRuleViolation::invalidDomain($domain);
        }
        if ($details->environmentId !== null && ! $project->environments()->whereKey($details->environmentId)->exists()) {
            throw AnalyticsRuleViolation::environmentNotInProject();
        }

        if ($site === null) {
            $sites = AnalyticsSite::query()->whereIn('project_id', Project::query()->where('account_id', $project->account_id)->select('id'))->count();
            $decision = $this->entitlements->for($project->account)->allows('analytics.sites.max', $sites + 1);
            if (! $decision->allowed) {
                throw AnalyticsRuleViolation::siteLimitReached($decision->reason ?? __('Your Analytics plan has no room for another site.'));
            }
        }

        $site ??= new AnalyticsSite;
        $site->forceFill([
            'project_id' => $project->id,
            'environment_id' => $details->environmentId,
            'name' => trim($details->name),
            'domains' => array_values(array_unique($domains)),
            'timezone' => $details->timezone,
            'excluded_paths' => array_values(array_filter(array_map(trim(...), $details->excludedPaths))),
            'custom_properties' => $details->customProperties === [] ? null : $details->customProperties,
        ])->save();

        if (! $site->isVerified()) {
            $this->verify->handle($actor, $site);
        }

        return $site;
    }
}
