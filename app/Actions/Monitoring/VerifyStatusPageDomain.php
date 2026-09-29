<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Contracts\DnsResolver;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class VerifyStatusPageDomain
{
    /**
     * Create a new VerifyStatusPageDomain instance.
     *
     * @param  DnsResolver  $dns  Looks up the TXT record.
     */
    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * Look for the custom domain's TXT record and mark the domain verified when it's there. Returns whether the
     * domain is verified afterwards.
     *
     * @param  User  $actor
     * @param  StatusPage  $page
     * @return bool
     */
    public function handle(User $actor, StatusPage $page): bool
    {
        Gate::forUser($actor)->authorize('update', $page);
        $name = $page->domainRecordName();
        $value = $page->domainRecordValue();
        if ($name === null || $value === null) {
            return false;
        }
        if ($page->custom_domain_verified_at !== null) {
            return true;
        }
        if (! in_array($value, array_map(trim(...), $this->dns->txtRecords($name)), true)) {
            return false;
        }
        $page->forceFill(['custom_domain_verified_at' => now()])->save();

        return true;
    }
}
