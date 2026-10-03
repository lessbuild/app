<?php

declare(strict_types=1);

namespace App\Jobs\SiteAudits;

use App\Enums\SiteAuditStatus;
use App\Models\SiteAuditRun;
use App\Services\SiteAudits\SiteAuditRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class RunSiteAudit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The queue audits run on, with its own worker so a long audit doesn't hold up other jobs.
     *
     * @var string
     */
    public const QUEUE = 'site-audits';

    /**
     * One try: a failed run says why, and people start another.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Up to half an hour: several journeys on the site and each competitor.
     *
     * @var int
     */
    public int $timeout = 1800;

    /**
     * Create a new RunSiteAudit instance.
     *
     * Runs one queued Audit run.
     *
     * @param  int  $runId  The run.
     */
    public function __construct(public readonly int $runId)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Run the audit.
     *
     * @param  SiteAuditRunner  $runner
     * @return void
     */
    public function handle(SiteAuditRunner $runner): void
    {
        $run = SiteAuditRun::query()->with('audit.project.account')->find($this->runId);
        if ($run !== null) {
            $runner->run($run);
        }
    }

    /**
     * Mark the run failed when the job itself dies (a timeout, or the worker stopping).
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        SiteAuditRun::query()->whereKey($this->runId)->whereIn('status', [SiteAuditStatus::Queued->value, SiteAuditStatus::Running->value])
            ->update(['status' => SiteAuditStatus::Failed->value, 'error' => __('The audit took too long and was stopped.'), 'finished_at' => now(), 'updated_at' => now()]);
    }
}
