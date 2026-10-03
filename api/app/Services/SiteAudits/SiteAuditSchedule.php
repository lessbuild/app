<?php

declare(strict_types=1);

namespace App\Services\SiteAudits;

use App\Actions\SiteAudits\QueueSiteAuditRun;
use App\Enums\SiteAuditSchedule as Schedule;
use App\Exceptions\AccountRuleViolation;
use App\Models\SiteAudit;
use App\Services\Billing\Entitlements;

/** Starts the audits whose schedule is due. */
final class SiteAuditSchedule
{
    /**
     * Create a new SiteAuditSchedule instance.
     *
     * @param  QueueSiteAuditRun  $queue  Starts each run.
     * @param  Entitlements  $entitlements  Says whether the plan still includes the schedule.
     */
    public function __construct(private readonly QueueSiteAuditRun $queue, private readonly Entitlements $entitlements) {}

    /**
     * Queue every due audit whose plan still includes its schedule and whose project still has Audit on, and set when
     * each runs next. An audit over the month's allowance is skipped until its next date.
     *
     * @return int how many runs were queued
     */
    public function queueDue(): int
    {
        $queued = 0;
        SiteAudit::query()->where('schedule', '!=', Schedule::None->value)->where('next_run_at', '<=', now())->with('project.account')
            ->chunkById(100, function ($audits) use (&$queued): void {
                foreach ($audits as $audit) {
                    $audit->forceFill(['next_run_at' => $audit->schedule->nextRunAfter(now())])->save();
                    $flag = $audit->schedule->flag();
                    if (! $audit->project->hasService('audit') || ($flag !== null && ! $this->entitlements->for($audit->project->account)->has($flag))) {
                        continue;
                    }
                    try {
                        $this->queue->handle($audit);
                        $queued++;
                    } catch (AccountRuleViolation) {
                        // Over the month's allowance: the next scheduled date tries again.
                    }
                }
            });

        return $queued;
    }
}
