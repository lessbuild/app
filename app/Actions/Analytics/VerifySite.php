<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsSite;
use App\Models\User;
use App\Queries\Projects\VerifiedHostnamesQuery;
use Illuminate\Support\Facades\Gate;

final class VerifySite
{
    /**
     * Create a new VerifySite instance.
     *
     * Checks that a site's hostnames belong to its project.
     *
     * @param  VerifiedHostnamesQuery  $verified  The project's verified domains.
     */
    public function __construct(private readonly VerifiedHostnamesQuery $verified) {}

    /**
     * Verify the site so it may collect: one of its hostnames must be a verified domain of its project, or a subdomain
     * of one, so nobody can point a tracker at a website they don't control. Returns whether it is verified.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @return bool
     */
    public function handle(User $actor, AnalyticsSite $site): bool
    {
        Gate::forUser($actor)->authorize('update', $site);
        if ($site->isVerified()) {
            return true;
        }

        $verified = $this->verified->handle($site->project);
        foreach ($site->domains as $domain) {
            foreach ($verified as $owned) {
                if ($domain === $owned || str_ends_with($domain, '.'.$owned)) {
                    $site->forceFill(['verified_at' => now()])->save();

                    return true;
                }
            }
        }

        return false;
    }
}
