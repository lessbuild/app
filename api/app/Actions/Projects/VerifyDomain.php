<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Contracts\DnsResolver;
use App\Events\Projects\DomainVerified;
use App\Exceptions\ProjectRuleViolation;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;

final class VerifyDomain
{
    /**
     * Create a new VerifyDomain instance.
     *
     * Checks a domain's TXT record.
     *
     * @param  DnsResolver  $dns  Looks up TXT records.
     */
    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * Look for the domain's TXT record. Returns whether the domain is verified afterwards.
     *
     * @param  User  $actor
     * @param  Domain  $domain
     * @return bool
     */
    public function handle(User $actor, Domain $domain): bool
    {
        Gate::forUser($actor)->authorize('update', $domain->project);
        if ($domain->verified_at !== null) {
            return true;
        }

        $found = in_array($domain->recordValue(), array_map(trim(...), $this->dns->txtRecords($domain->recordName())), true);
        $domain->forceFill(['last_checked_at' => now()]);
        if (! $found) {
            $domain->save();

            return false;
        }

        if (Domain::query()->where('hostname', $domain->hostname)->whereNotNull('verified_at')->whereKeyNot($domain->id)->exists()) {
            $domain->save();
            throw ProjectRuleViolation::domainVerifiedElsewhere();
        }
        try {
            $domain->forceFill(['verified_at' => now()])->save();
        } catch (UniqueConstraintViolationException) {
            throw ProjectRuleViolation::domainVerifiedElsewhere();
        }

        DomainVerified::dispatch($domain, $actor);

        return true;
    }
}
