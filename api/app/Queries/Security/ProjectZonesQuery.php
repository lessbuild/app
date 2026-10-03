<?php

declare(strict_types=1);

namespace App\Queries\Security;

use App\Enums\ProviderType;
use App\Models\Project;
use App\Models\Provider;
use App\Models\SecurityZone;
use App\Models\Website;
use App\Models\WebsiteDomain;
use Illuminate\Support\Collection;

/** The Cloudflare zones behind a project's websites, with the security settings chosen for each (defaults if none). */
final class ProjectZonesQuery
{
    /**
     * List the zones: every zone a website domain's DNS is managed in, with the project's saved settings or an unsaved
     * row holding the defaults, and the domains in each.
     *
     * @param  Project  $project
     * @return Collection<int, array{zone: SecurityZone, domains: non-empty-list<string>}>
     */
    public function handle(Project $project): Collection
    {
        $domains = WebsiteDomain::query()->whereIn('website_id', Website::query()->whereIn('environment_id', $project->environments()->select('id'))->select('id'))
            ->whereNotNull('dns_record_id')->whereIn('dns_provider_id', Provider::query()->where('type', ProviderType::Cloudflare)->select('id'))->get(['hostname', 'dns_provider_id', 'dns_record_id']);
        $saved = SecurityZone::query()->where('project_id', $project->id)->get()->keyBy('zone_id');
        $zones = [];
        foreach ($domains as $domain) {
            $zoneId = explode(':', (string) $domain->dns_record_id, 2)[0];
            if ($zoneId === '') {
                continue;
            }
            if (! isset($zones[$zoneId])) {
                $zone = $saved->get($zoneId) ?? (new SecurityZone)->forceFill(['project_id' => $project->id, 'provider_id' => $domain->dns_provider_id, 'zone_id' => $zoneId, 'security_level' => 'medium', 'bot_fight_mode' => false, 'under_attack' => false]);
                $zones[$zoneId] = ['zone' => $zone, 'domains' => []];
            }
            $zones[$zoneId]['domains'][] = $domain->hostname;
        }

        return collect(array_values($zones));
    }
}
