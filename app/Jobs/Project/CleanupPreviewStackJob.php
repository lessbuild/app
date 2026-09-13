<?php

namespace App\Jobs\Project;

use App\Actions\Project\CleanupPreviewStackAction;
use App\Models\PreviewStackCleanup;
use App\Services\PreviewStackCleanupScript;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CleanupPreviewStackJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $uniqueFor = 600;

    private ?string $claimToken = null;

    /**
     * Capture only the cleanup record identity so queued workers reload its current lease and target.
     *
     * @param  int  $cleanupId  Cleanup record retained for a durable retry.
     */
    public function __construct(public readonly int $cleanupId) {}

    /** @return string Cleanup identifier used by Laravel's unique-job lock. */
    public function uniqueId(): string
    {
        return (string) $this->cleanupId;
    }

    /**
     * Claim a cleanup lease, execute its remote idempotent operation, then record success.
     *
     * @param  CleanupPreviewStackAction  $cleanup  Remote cleanup operation.
     *
     * @throws Throwable When remote cleanup fails so Laravel retries the job.
     */
    public function handle(
        CleanupPreviewStackAction $cleanup,
        Runner $runner,
        PreviewStackCleanupScript $script,
    ): void {
        $record = $this->claim();
        if (! $record) {
            return;
        }

        $this->claimToken = (string) $record->claim_token;
        try {
            $cleanup->handle($record, $runner, $script);
        } catch (Throwable $exception) {
            $this->markFailed($exception);
            throw $exception;
        }

        DB::table('preview_stack_cleanups')
            ->where('id', $record->id)
            ->where('status', PreviewStackCleanup::STATUS_RUNNING)
            ->where('claim_token', $this->claimToken)
            ->update([
                'status' => PreviewStackCleanup::STATUS_SUCCEEDED,
                'claim_token' => null,
                'lease_expires_at' => null,
                'error' => null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Persist a bounded terminal failure when the queue exhausts retries.
     *
     * @param  Throwable  $exception  Final queue failure.
     */
    public function failed(Throwable $exception): void
    {
        $this->markFailed($exception);
    }

    private function claim(): ?PreviewStackCleanup
    {
        return DB::transaction(function (): ?PreviewStackCleanup {
            $record = PreviewStackCleanup::query()->lockForUpdate()->find($this->cleanupId);
            if (! $record || $record->status === PreviewStackCleanup::STATUS_SUCCEEDED) {
                return null;
            }
            if ($record->status === PreviewStackCleanup::STATUS_RUNNING && $record->lease_expires_at?->isFuture()) {
                return null;
            }

            $token = (string) Str::uuid();
            $record->update([
                'status' => PreviewStackCleanup::STATUS_RUNNING,
                'attempts' => $record->attempts + 1,
                'claim_token' => $token,
                'lease_expires_at' => now()->addMinutes(10),
                'started_at' => now(),
                'error' => null,
            ]);

            return $record->fresh();
        }, 5);
    }

    private function markFailed(Throwable $exception): void
    {
        if ($this->claimToken === null) {
            return;
        }

        DB::table('preview_stack_cleanups')
            ->where('id', $this->cleanupId)
            ->where('status', PreviewStackCleanup::STATUS_RUNNING)
            ->where('claim_token', $this->claimToken)
            ->update([
                'status' => PreviewStackCleanup::STATUS_FAILED,
                'claim_token' => null,
                'lease_expires_at' => null,
                'error' => str($exception->getMessage())->limit(2000)->toString(),
                'updated_at' => now(),
            ]);
    }
}
