<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\AnalyticsSite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class StopSharingSiteReport
{
    /**
     * Create a new StopSharingSiteReport instance.
     *
     * @param  RecordAuditEntry  $audit  Records who stopped it.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Stop sharing a site's report: the link stops working straight away.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @return void
     */
    public function handle(User $actor, AnalyticsSite $site): void
    {
        Gate::forUser($actor)->authorize('update', $site);

        $site->forceFill(['share_token' => null, 'share_password' => null, 'shared_at' => null])->save();
        $this->audit->handle(AuditAction::AnalyticsReportUnshared, $actor, $site->project->account_id, ['site' => $site->name], $site->project_id);
    }
}
