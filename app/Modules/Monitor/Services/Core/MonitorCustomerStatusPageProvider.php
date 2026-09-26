<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\CustomerStatusPageProvider;
use App\Core\Data\Status\CustomerStatusPage as CoreCustomerStatusPage;
use App\Modules\Monitor\Models\StatusPage;
use App\Modules\Monitor\Services\StatusPageReport;

final class MonitorCustomerStatusPageProvider implements CustomerStatusPageProvider
{
    public function __construct(private readonly StatusPageReport $reports) {}

    public function findPublished(string $slug): ?CoreCustomerStatusPage
    {
        if (! config('platform.products.monitor.enabled', false)) {
            return null;
        }

        $page = StatusPage::query()
            ->with('workspace')
            ->where('slug', $slug)
            ->where('published', true)
            ->first();
        if (! $page instanceof StatusPage || $page->workspace === null || MonitorDeletionFence::workspaceIsFenced($page->workspace_id)) {
            return null;
        }

        $report = $this->reports->report($page);

        return new CoreCustomerStatusPage(
            product: 'monitor',
            slug: (string) $page->slug,
            name: (string) $page->name,
            workspaceName: (string) $page->workspace->name,
            description: $page->description,
            overall: $report['overall'],
            overallLabel: $report['overallLabel'],
            components: $report['components'],
            incidents: $report['incidents'],
            recentIncidents: $report['recentIncidents'],
        );
    }

    public function subscribe(string $slug, string $email): bool
    {
        return false;
    }
}
