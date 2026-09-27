<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Data\Analytics\SiteDetails;
use App\Exceptions\AnalyticsRuleViolation;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use App\Support\Hostname;
use Illuminate\Support\Facades\Gate;

final class SaveSite
{
    /**
     * Creates or changes an analytics site.
     *
     * @param  VerifySite  $verify  Verifies it once its domains are saved.
     */
    public function __construct(private readonly VerifySite $verify) {}

    /** Create a site in the project, or update one. New sites are verified straight away when a domain is already verified in the project. */
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

        $site ??= new AnalyticsSite;
        $site->forceFill([
            'project_id' => $project->id,
            'environment_id' => $details->environmentId,
            'name' => trim($details->name),
            'domains' => array_values(array_unique($domains)),
            'timezone' => $details->timezone,
            'excluded_paths' => array_values(array_filter(array_map(trim(...), $details->excludedPaths))),
        ])->save();

        if (! $site->isVerified()) {
            $this->verify->handle($actor, $site);
        }

        return $site;
    }
}
