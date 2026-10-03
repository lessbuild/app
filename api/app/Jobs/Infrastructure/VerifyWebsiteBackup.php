<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Enums\AccountRole;
use App\Models\BackupVerification;
use App\Notifications\RestoreDrillFailedNotification;
use App\Services\Infrastructure\BackupScripts;
use App\Services\Infrastructure\ServerShell;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Restores a snapshot into a temporary directory and database, checks it, and records how far it got from the script's markers. */
final class VerifyWebsiteBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * One attempt; a failed verification is a result worth keeping, and someone can run another.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Restoring a large snapshot to check it can take up to an hour.
     *
     * @var int
     */
    public int $timeout = 3600;

    private const MESSAGES = [
        'preflight' => 'The website isn’t ready for verification (it needs a deployed release and its server).',
        'restore' => 'The snapshot couldn’t be restored into the temporary directory.',
        'integrity' => 'The restored database or storage didn’t pass the integrity checks.',
        'smoke' => 'The application couldn’t read the temporary database.',
        'cleanup' => 'The temporary directory or database couldn’t be removed.',
    ];

    /**
     * Create a new VerifyWebsiteBackup instance.
     *
     * Proves a backup can be restored: restores it into a temporary directory and database, checks them, and removes
     * them again.
     *
     * @param  int  $verificationId  The queued verification.
     */
    public function __construct(public readonly int $verificationId) {}

    /**
     * Claim the verification, runs the verification script, and reads the stage markers it prints to decide which
     * stage (if any) failed.
     *
     * @param  ServerShell  $shell
     * @param  BackupScripts  $scripts
     * @return void
     */
    public function handle(ServerShell $shell, BackupScripts $scripts): void
    {
        if (BackupVerification::query()->whereKey($this->verificationId)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]) === 0) {
            return;
        }
        $verification = BackupVerification::query()->with(['backup.website.server', 'backup.destination'])->findOrFail($this->verificationId);
        $backup = $verification->backup;
        $server = $backup->website->server;
        if ($server === null || ! $backup->isRestorable() || ! hash_equals($verification->snapshot_id, (string) $backup->snapshot_id)) {
            $this->finish($verification, 'preflight');

            return;
        }
        $result = $shell->run($server, $scripts->verify($verification));
        $markers = [];
        foreach (['failure_stage', 'integrity_status', 'smoke_status', 'cleanup_status'] as $marker) {
            if (preg_match('/^BP_'.strtoupper($marker).'=(\w+)$/m', $result->output, $match) === 1) {
                $markers[$marker] = strtolower($match[1]);
            }
        }
        $passed = fn (string $marker): bool => ($markers[$marker] ?? null) === 'passed';
        $stage = match (true) {
            ! $passed('cleanup_status') => 'cleanup',
            ! $passed('integrity_status') => in_array($markers['failure_stage'] ?? null, ['preflight', 'restore'], true) ? $markers['failure_stage'] : 'integrity',
            ! $passed('smoke_status') => 'smoke',
            ! $result->successful() => 'cleanup',
            default => null,
        };
        $this->finish($verification, $stage, [
            'integrity_status' => $passed('integrity_status') ? 'passed' : ($stage === 'integrity' ? 'failed' : 'pending'),
            'smoke_status' => $passed('smoke_status') ? 'passed' : ($stage === 'smoke' ? 'failed' : 'pending'),
            'cleanup_status' => $passed('cleanup_status') ? 'passed' : 'failed',
        ]);
    }

    /**
     * Mark an unfinished verification failed at the restore stage.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        $verification = BackupVerification::query()->find($this->verificationId);
        if ($verification !== null && $verification->status !== 'succeeded') {
            $this->finish($verification, 'restore');
        }
    }

    /**
     * Store the outcome, the per-check statuses, a message for the failed stage and how long it took.
     *
     * @param  BackupVerification  $verification
     * @param  string|null  $stage
     * @param  array<string, string>  $checks
     * @return void
     */
    private function finish(BackupVerification $verification, ?string $stage, array $checks = []): void
    {
        $verification->forceFill([
            ...$checks,
            'status' => $stage === null ? 'succeeded' : 'failed',
            'failure_stage' => $stage,
            'error' => $stage === null ? null : self::MESSAGES[$stage],
            'completed_at' => now(),
            'duration_seconds' => $verification->started_at === null ? null : (int) $verification->started_at->diffInSeconds(now()),
        ])->save();
        // A failed monthly drill (not one someone asked for) tells the account's owners.
        if ($stage !== null && $verification->requested_by === null) {
            $website = $verification->backup->website;
            $owners = $website->account->members()->wherePivot('role', AccountRole::Owner->value)->get();
            $projectId = $website->environment?->project_id;
            Notification::send($owners, new RestoreDrillFailedNotification($verification, $projectId !== null ? route('infrastructure.websites.show', [$projectId, $website->id, 'tab' => 'backups']) : route('dashboard')));
        }
    }
}
