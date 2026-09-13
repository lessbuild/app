<?php

namespace App\Jobs\Web;

use App\Models\BackupRestoreVerification;
use App\Models\WebsiteBackup;
use App\Services\ResticRepository;
use App\Services\Runner;
use App\Services\VerifyWebsiteBackupScript;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class VerifyWebsiteBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * Capture the persisted isolated verification and resolve its backup at execution time.
     *
     * @param  int  $verificationId  Verification record identifying the exact retained snapshot.
     */
    public function __construct(public readonly int $verificationId) {}

    /**
     * Restore into a temporary same-server target, run integrity and application checks, and persist sanitized evidence.
     *
     * @param  Runner  $runner  SSH runner used to execute the isolated verification script.
     * @param  ResticRepository  $repositories  Restic configuration service supplying the encrypted destination environment.
     * @param  VerifyWebsiteBackupScript  $script  Builder for the temporary-target shell protocol and cleanup markers.
     */
    public function handle(
        Runner $runner,
        ResticRepository $repositories,
        VerifyWebsiteBackupScript $script,
    ): void {
        $verification = BackupRestoreVerification::query()
            ->with(['backup.website.server', 'backup.destination'])
            ->find($this->verificationId);
        if (! $verification || $verification->status !== BackupRestoreVerification::STATUS_QUEUED) {
            return;
        }

        $verification->update([
            'status' => BackupRestoreVerification::STATUS_RUNNING,
            'integrity_status' => BackupRestoreVerification::CHECK_PENDING,
            'smoke_status' => BackupRestoreVerification::CHECK_PENDING,
            'cleanup_status' => BackupRestoreVerification::CLEANUP_PENDING,
            'failure_stage' => null,
            'duration_seconds' => null,
            'started_at' => now(),
            'completed_at' => null,
            'error' => null,
        ]);
        $verification->refresh();

        $stage = BackupRestoreVerification::STAGE_PREFLIGHT;

        try {
            $backup = $verification->backup;
            $website = $backup?->website;
            if (! $backup || ! $website
                || $verification->target_type !== BackupRestoreVerification::TARGET_SAME_SERVER_TEMPORARY
                || $verification->overwrite_mode !== BackupRestoreVerification::OVERWRITE_NEVER
                || $backup->status !== WebsiteBackup::STATUS_SUCCEEDED
                || ! hash_equals((string) $verification->snapshot_id, (string) $backup->snapshot_id)
                || preg_match('/\A[a-f0-9]{8,64}\z/D', (string) $verification->snapshot_id) !== 1) {
                throw new RuntimeException('The retained snapshot changed or is no longer restorable.');
            }

            if ($website->hasActiveDeployment()) {
                throw new RuntimeException('A deployment started before isolated verification could run.');
            }

            if (! $website->server?->mysql_root_password) {
                throw new RuntimeException('The managed server does not have a stored MySQL root credential.');
            }

            $restic = $repositories->shell($backup->destination, $website);
            $stage = BackupRestoreVerification::STAGE_RESTORE;
            $result = $runner->server($website->server)->create(false)->execute($script->for($verification, $restic));
            $successful = $result->isSuccessful();
            $markers = $this->markers((string) $result->getOutput());
            $cleanupStatus = $markers['cleanup'] ?? BackupRestoreVerification::CLEANUP_FAILED;
            $failureStage = $this->failureStage($markers, $stage, $successful);
            $verification->update([
                'integrity_status' => ($markers['integrity'] ?? null) === BackupRestoreVerification::CHECK_PASSED
                    ? BackupRestoreVerification::CHECK_PASSED
                    : ($failureStage === BackupRestoreVerification::STAGE_INTEGRITY
                        ? BackupRestoreVerification::CHECK_FAILED
                        : BackupRestoreVerification::CHECK_PENDING),
                'smoke_status' => ($markers['smoke'] ?? null) === BackupRestoreVerification::CHECK_PASSED
                    ? BackupRestoreVerification::CHECK_PASSED
                    : ($failureStage === BackupRestoreVerification::STAGE_SMOKE
                        ? BackupRestoreVerification::CHECK_FAILED
                        : BackupRestoreVerification::CHECK_PENDING),
                'cleanup_status' => $cleanupStatus,
            ]);

            if (! $successful
                || ($markers['integrity'] ?? null) !== BackupRestoreVerification::CHECK_PASSED
                || ($markers['smoke'] ?? null) !== BackupRestoreVerification::CHECK_PASSED
                || $cleanupStatus !== BackupRestoreVerification::CLEANUP_PASSED) {
                $stage = $failureStage;
                throw new RuntimeException($this->message($failureStage));
            }

            $verification->update([
                'status' => BackupRestoreVerification::STATUS_SUCCEEDED,
                'integrity_status' => BackupRestoreVerification::CHECK_PASSED,
                'smoke_status' => BackupRestoreVerification::CHECK_PASSED,
                'cleanup_status' => BackupRestoreVerification::CLEANUP_PASSED,
                'failure_stage' => null,
                'completed_at' => now(),
                'duration_seconds' => $this->duration($verification),
            ]);
        } catch (Throwable $exception) {
            $this->markFailed($verification, $stage, $exception);
            throw $exception;
        }
    }

    /**
     * Keep queue failure handling from exposing remote command output or credentials.
     */
    public function failed(Throwable $exception): void
    {
        $verification = BackupRestoreVerification::query()->find($this->verificationId);
        if (! $verification || $verification->status === BackupRestoreVerification::STATUS_SUCCEEDED) {
            return;
        }

        $stage = in_array($verification->failure_stage, [
            BackupRestoreVerification::STAGE_PREFLIGHT,
            BackupRestoreVerification::STAGE_RESTORE,
            BackupRestoreVerification::STAGE_INTEGRITY,
            BackupRestoreVerification::STAGE_SMOKE,
            BackupRestoreVerification::STAGE_CLEANUP,
        ], true) ? $verification->failure_stage : BackupRestoreVerification::STAGE_PREFLIGHT;
        $this->markFailed($verification, $stage, $exception);
    }

    /**
     * Parse only the bounded markers emitted by the verification script.
     *
     * @return array{failure_stage?: string, integrity?: string, smoke?: string, cleanup?: string}
     */
    private function markers(string $output): array
    {
        $markers = [];
        foreach (['FAILURE_STAGE' => 'failure_stage', 'INTEGRITY_STATUS' => 'integrity', 'SMOKE_STATUS' => 'smoke', 'CLEANUP_STATUS' => 'cleanup'] as $name => $key) {
            if (preg_match('/^BP_'.preg_quote($name, '/').'=(\w+)$/m', $output, $match) === 1) {
                $markers[$key] = strtolower($match[1]);
            }
        }

        return $markers;
    }

    /**
     * Resolve the first failed protocol stage, treating missing cleanup evidence as failure.
     *
     * @param  array{failure_stage?: string, integrity?: string, smoke?: string, cleanup?: string}  $markers
     */
    private function failureStage(array $markers, string $fallback, bool $successful): string
    {
        if (($markers['cleanup'] ?? null) !== BackupRestoreVerification::CLEANUP_PASSED) {
            return BackupRestoreVerification::STAGE_CLEANUP;
        }

        if (isset($markers['failure_stage']) && in_array($markers['failure_stage'], [
            BackupRestoreVerification::STAGE_PREFLIGHT,
            BackupRestoreVerification::STAGE_RESTORE,
            BackupRestoreVerification::STAGE_INTEGRITY,
            BackupRestoreVerification::STAGE_SMOKE,
        ], true)) {
            return $markers['failure_stage'];
        }

        if (($markers['integrity'] ?? null) !== BackupRestoreVerification::CHECK_PASSED) {
            return BackupRestoreVerification::STAGE_INTEGRITY;
        }

        if (($markers['smoke'] ?? null) !== BackupRestoreVerification::CHECK_PASSED) {
            return BackupRestoreVerification::STAGE_SMOKE;
        }

        return $successful ? BackupRestoreVerification::STAGE_CLEANUP : $fallback;
    }

    private function duration(BackupRestoreVerification $verification): ?int
    {
        return $verification->started_at?->diffInSeconds(now());
    }

    private function message(string $stage): string
    {
        return match ($stage) {
            BackupRestoreVerification::STAGE_RESTORE => 'The retained snapshot could not be restored into the temporary target.',
            BackupRestoreVerification::STAGE_INTEGRITY => 'The restored database or storage did not pass integrity checks.',
            BackupRestoreVerification::STAGE_SMOKE => 'The application smoke check did not pass against the temporary database.',
            BackupRestoreVerification::STAGE_CLEANUP => 'The temporary verification target could not be cleaned up.',
            default => 'Isolated restore verification prerequisites were not met.',
        };
    }

    private function markFailed(BackupRestoreVerification $verification, string $stage, Throwable $exception): void
    {
        $verification->update([
            'status' => BackupRestoreVerification::STATUS_FAILED,
            'integrity_status' => $stage === BackupRestoreVerification::STAGE_INTEGRITY
                ? BackupRestoreVerification::CHECK_FAILED
                : $verification->integrity_status,
            'smoke_status' => $stage === BackupRestoreVerification::STAGE_SMOKE
                ? BackupRestoreVerification::CHECK_FAILED
                : $verification->smoke_status,
            'cleanup_status' => $verification->cleanup_status === BackupRestoreVerification::CLEANUP_PASSED
                ? BackupRestoreVerification::CLEANUP_PASSED
                : ($stage === BackupRestoreVerification::STAGE_CLEANUP
                    ? BackupRestoreVerification::CLEANUP_FAILED
                    : $verification->cleanup_status),
            'failure_stage' => $stage,
            'completed_at' => now(),
            'duration_seconds' => $this->duration($verification),
            'error' => $this->message($stage),
        ]);
    }
}
