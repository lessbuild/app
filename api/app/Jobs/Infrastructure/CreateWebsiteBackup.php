<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\WebsiteBackup;
use App\Services\Infrastructure\BackupScripts;
use App\Services\Infrastructure\ServerShell;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/** Runs a queued backup on the website's server and records the restic snapshot. A failed attempt is retried once. */
final class CreateWebsiteBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Two attempts, since a backup can fail on a busy server or storage.
     *
     * @var int
     */
    public int $tries = 2;

    /**
     * Backing up a large website can take up to an hour.
     *
     * @var int
     */
    public int $timeout = 3600;

    /**
     * Create a new CreateWebsiteBackup instance.
     *
     * Takes a restic snapshot of a website's files and database.
     *
     * @param  int  $backupId  The queued backup.
     */
    public function __construct(public readonly int $backupId) {}

    /**
     * Claim the backup, runs the backup script, and stores the snapshot ID and size restic reports. On failure the
     * backup is put back in the queue so the retry can claim it.
     *
     * @param  ServerShell  $shell
     * @param  BackupScripts  $scripts
     * @return void
     */
    public function handle(ServerShell $shell, BackupScripts $scripts): void
    {
        if (WebsiteBackup::query()->whereKey($this->backupId)->where('status', WebsiteBackup::STATUS_QUEUED)
            ->update(['status' => WebsiteBackup::STATUS_RUNNING, 'started_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'), 'error' => null]) === 0) {
            return;
        }
        $backup = WebsiteBackup::query()->with(['website.server', 'destination', 'schedule.secondaryDestination'])->findOrFail($this->backupId);
        try {
            $server = $backup->website->server ?? throw new RuntimeException('The website has no server.');
            $result = $shell->run($server, $scripts->backup($backup));
            if (! $result->successful()) {
                throw new RuntimeException(trim($result->errorOutput ?: $result->output) ?: 'The backup failed on the server.');
            }
            if (preg_match('/"snapshot_id"\s*:\s*"([a-f0-9]{8,64})"/i', $result->output, $snapshot) !== 1) {
                throw new RuntimeException('Restic didn’t report a snapshot.');
            }
            preg_match('/"total_bytes_processed"\s*:\s*(\d+)/', $result->output, $bytes);
        } catch (Throwable $exception) {
            // Let the retry pick it up again; failed() records the error once attempts run out.
            $backup->forceFill(['status' => WebsiteBackup::STATUS_QUEUED, 'started_at' => null])->save();

            throw $exception;
        }
        $backup->forceFill([
            'status' => WebsiteBackup::STATUS_SUCCEEDED, 'snapshot_id' => strtolower($snapshot[1]), 'size_bytes' => isset($bytes[1]) ? (int) $bytes[1] : null,
            'https_verified_at' => now(), 'completed_at' => now(),
            'secondary_status' => preg_match('/BUILDPUSHER_SECOND_COPY=(copied|failed)/', $result->output, $second) === 1 ? $second[1] : null,
        ])->save();
        $backup->destination->forceFill(['last_verified_at' => now(), 'last_error' => null])->save();
    }

    /**
     * Mark the backup failed once attempts run out.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        WebsiteBackup::query()->whereKey($this->backupId)->whereIn('status', [WebsiteBackup::STATUS_QUEUED, WebsiteBackup::STATUS_RUNNING])->first()
            ?->forceFill(['status' => WebsiteBackup::STATUS_FAILED, 'completed_at' => now(), 'error' => str($exception->getMessage())->limit(2000)->toString()])->save();
    }
}
