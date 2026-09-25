<?php

namespace App\Modules\Analytics\Actions\Sites;

use App\Core\Data\Billing\ProductPlanResolution;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateSiteForWorkspace
{
    /** @param array{name: string, slug: string, domains: list<string>, timezone: string} $attributes */
    public function handle(Workspace $workspace, ProductPlanResolution $plan, array $attributes): Site
    {
        abort_unless($plan->available, 503, 'Analytics could not confirm this workspace plan.');
        abort_unless($plan->allows('site_management'), 403, 'The current Analytics plan does not allow site management.');
        abort_unless($plan->hasLimit('sites'), 503, 'The current Analytics plan has no reconciled site limit.');

        return DB::connection('analytics')->transaction(function () use ($workspace, $plan, $attributes): Site {
            $lockedWorkspace = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
            $siteLimit = $plan->limit('sites');
            $currentSites = $lockedWorkspace->sites()->count();

            if ($siteLimit !== null && $currentSites >= $siteLimit) {
                throw ValidationException::withMessages([
                    'sites' => __('This workspace has reached its Analytics site limit for the current plan.'),
                ]);
            }

            return $lockedWorkspace->sites()->create($attributes);
        }, attempts: 3);
    }
}
