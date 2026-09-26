<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Contracts\DnsResolver;
use App\Domain\Projects\Events\DomainVerified;
use App\Domain\Projects\Exceptions\ProjectRuleViolation;
use App\Domain\Projects\Models\Domain;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;

final class VerifyDomain
{
    public function __construct(private readonly DnsResolver $dns) {}

    /** Look for the domain's TXT record. Returns whether the domain is verified afterwards. */
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
