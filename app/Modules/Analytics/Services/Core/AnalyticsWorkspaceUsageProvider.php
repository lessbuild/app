<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\WorkspaceProductUsageProvider;
use App\Core\Data\Billing\ProductUsageMeter;
use App\Core\Data\Billing\ProductUsageSummary;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\LegacyIdentityResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AnalyticsWorkspaceUsageProvider implements WorkspaceProductUsageProvider
{
    public function __construct(private readonly LegacyIdentityResolver $identities) {}

    public function summarize(CoreWorkspace $workspace): ?ProductUsageSummary
    {
        if (! Schema::connection('analytics')->hasTable('workspace_usage_periods')) {
            throw new \RuntimeException('The Analytics workspace usage meter is unavailable.');
        }

        $sourceWorkspaceIds = $this->identities->sourceIdsForCanonical(
            product: 'analytics',
            sourceEntity: 'workspace',
            canonicalId: (string) $workspace->getKey(),
            canonicalEntity: 'workspace',
        );

        if ($sourceWorkspaceIds === [] || collect($sourceWorkspaceIds)->contains(fn (string $id): bool => ! ctype_digit($id))) {
            return null;
        }

        $periodStart = CarbonImmutable::now('UTC')->startOfMonth();
        $periodEnd = $periodStart->addMonth()->subDay();
        $acceptedEvents = DB::connection('analytics')->table('workspace_usage_periods')
            ->whereIn('workspace_id', $sourceWorkspaceIds)
            ->whereDate('period_start', $periodStart->toDateString())
            ->sum('accepted_events');

        return new ProductUsageSummary(
            periodLabel: __('UTC calendar month :start through :end', [
                'start' => $periodStart->format('M j, Y'),
                'end' => $periodEnd->format('M j, Y'),
            ]),
            meters: [new ProductUsageMeter(
                key: 'events_per_month',
                label: __('Accepted Analytics events'),
                used: max(0, (int) $acceptedEvents),
            )],
        );
    }
}
