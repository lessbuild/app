<?php

declare(strict_types=1);

namespace App\Jobs\Security;

use App\Actions\Security\RecordFindings;
use App\Models\SecurityFinding;
use App\Models\SecurityScan;
use App\Services\Security\Scanners;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class RunSecurityScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * One try: the next scheduled scan tries again.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Up to ten minutes.
     *
     * @var int
     */
    public int $timeout = 600;

    /**
     * Create a new RunSecurityScan instance.
     *
     * Runs one queued Security scan.
     *
     * @param  int  $scanId  The scan.
     */
    public function __construct(public readonly int $scanId) {}

    /**
     * Run the scanner, record what it found per scope, resolve findings in scopes that are gone (except those the
     * deploy gate recorded), and store how many are open.
     *
     * @param  Scanners  $scanners
     * @param  RecordFindings  $record
     * @return void
     */
    public function handle(Scanners $scanners, RecordFindings $record): void
    {
        $scan = SecurityScan::query()->with('project')->find($this->scanId);
        $scanner = $scan === null ? null : $scanners->find($scan->kind);
        if ($scan === null || $scanner === null || $scan->status !== 'queued') {
            return;
        }
        $scan->forceFill(['status' => 'running', 'started_at' => now()])->save();
        $project = $scan->project;
        $open = 0;
        $scopes = [];
        foreach ($scanner->scan($project) as $scope => $findings) {
            $scopes[] = (string) $scope;
            $open += $record->handle($project, $scanner->kind(), (string) $scope, $findings);
        }
        SecurityFinding::query()->where('project_id', $project->id)->where('source', $scanner->kind())->where('status', 'open')
            ->whereNotIn('scope', $scopes)->where('scope', 'not like', 'gate:%')->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);
        $scan->forceFill(['status' => 'done', 'findings_count' => $open, 'finished_at' => now(), 'error' => null])->save();
    }

    /**
     * Record why the scan failed.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        SecurityScan::query()->whereKey($this->scanId)->update(['status' => 'failed', 'finished_at' => now(), 'error' => str($exception->getMessage())->limit(1000)->toString()]);
    }
}
