<?php

declare(strict_types=1);

namespace App\Actions\SiteAudits;

use App\Enums\SiteAuditStatus;
use App\Exceptions\AccountRuleViolation;
use App\Jobs\SiteAudits\RunSiteAudit;
use App\Models\SiteAudit;
use App\Models\SiteAuditRun;
use App\Models\User;
use App\Services\Billing\Overage;
use App\Services\SiteAudits\SiteAuditUsage;
use Illuminate\Support\Facades\Gate;

final class QueueSiteAuditRun
{
    /**
     * Create a new QueueSiteAuditRun instance.
     *
     * @param  Overage  $overage  Says whether the plan allows another audit this month.
     * @param  SiteAuditUsage  $usage  Counts this month's audits.
     */
    public function __construct(private readonly Overage $overage, private readonly SiteAuditUsage $usage) {}

    /**
     * Queue a run of an audit, by someone ("Run audit") or the schedule (no actor). A run already waiting or running
     * is returned instead of starting another.
     *
     * @param  SiteAudit  $audit
     * @param  User|null  $actor
     * @return SiteAuditRun
     *
     * @throws AccountRuleViolation when the month's audits are used up
     */
    public function handle(SiteAudit $audit, ?User $actor = null): SiteAuditRun
    {
        $project = $audit->project;
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('manageService', [$project, 'audit']);
        }
        $pending = $audit->runs()->whereIn('status', [SiteAuditStatus::Queued->value, SiteAuditStatus::Running->value])->first();
        if ($pending !== null) {
            return $pending;
        }
        if (! $this->overage->allows($project->account, SiteAuditUsage::METER, $this->usage->usedThisMonth($project->account_id), 1)) {
            throw new AccountRuleViolation('audit', __('You’ve used this month’s audits. Upgrade on the billing page, or turn on pay-as-you-go, to run more.'));
        }

        $run = new SiteAuditRun;
        $run->forceFill([
            'site_audit_id' => $audit->id, 'project_id' => $project->id, 'status' => SiteAuditStatus::Queued,
            'trigger' => $actor === null ? 'scheduled' : 'manual', 'requested_by' => $actor?->id,
        ])->save();
        RunSiteAudit::dispatch($run->id)->afterCommit();

        return $run;
    }
}
