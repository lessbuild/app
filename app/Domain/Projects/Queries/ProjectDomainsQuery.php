<?php

declare(strict_types=1);

namespace App\Domain\Projects\Queries;

use App\Domain\Projects\Data\DomainRow;
use App\Domain\Projects\Models\Domain;
use App\Domain\Projects\Models\Project;
use Carbon\CarbonImmutable;

final class ProjectDomainsQuery
{
    /** @return list<DomainRow> unverified first, then alphabetical */
    public function handle(Project $project): array
    {
        return array_values($project->domains()->with('environment')->get()
            ->sortBy([fn (Domain $a, Domain $b): int => ($a->verified_at !== null) <=> ($b->verified_at !== null), fn (Domain $a, Domain $b): int => strcmp($a->hostname, $b->hostname)])
            ->map(fn (Domain $domain): DomainRow => new DomainRow(
                id: $domain->id,
                name: $domain->displayName(),
                environment: $domain->environment?->name,
                verifiedAt: $domain->verified_at ? CarbonImmutable::instance($domain->verified_at) : null,
                lastCheckedAt: $domain->last_checked_at ? CarbonImmutable::instance($domain->last_checked_at) : null,
                recordName: $domain->recordName(),
                recordValue: $domain->recordValue(),
            ))
            ->all());
    }
}
