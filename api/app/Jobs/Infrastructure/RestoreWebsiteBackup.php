<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\BackupRestore;
use App\Services\Infrastructure\BackupScripts;
use App\Services\Infrastructure\ServerShell;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/** Restores a snapshot over the live website. Never retried: the script rolls back on failure and a person decides again. */
final class RestoreWebsiteBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * One attempt: the restore script puts the website back as it was if it fails, and running it twice isn't safe.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Restores of large websites can take up to an hour.
     *
     * @var int
     */
    public int $timeout = 3600;

    /**
     * Create a new RestoreWebsiteBackup instance.
     *
     * Restores a website from one of its backups.
     *
     * @param  int  $restoreId  The queued restore.
     */
    public function __construct(public readonly int $restoreId) {}

    /**
     * Claim the restore and runs the restore script on the website's server, recording success or the script's error.
     *
     * @param  ServerShell  $shell
     * @param  BackupScripts  $scripts
     * @return void
     */
    public function handle(ServerShell $shell, BackupScripts $scripts): void
    {
        if (BackupRestore::query()->whereKey($this->restoreId)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'), 'error' => null]) === 0) {
            return;
        }
        $restore = BackupRestore::query()->with(['backup.website.server', 'backup.destination'])->findOrFail($this->restoreId);
        $server = $restore->backup->website->server;
        if ($server === null || ! $restore->backup->isRestorable()) {
            $this->failed(new RuntimeException('The backup doesn’t have a snapshot to restore, or the website has no server.'));

            return;
        }
        $result = $shell->run($server, $scripts->restore($restore));
        if (! $result->successful()) {
            $this->failed(new RuntimeException(trim($result->errorOutput ?: $result->output) ?: 'The restore failed; the website was put back as it was.'));

            return;
        }
        $restore->forceFill(['status' => 'succeeded', 'completed_at' => now()])->save();
    }

    /**
     * Mark the restore failed with the reason.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        BackupRestore::query()->whereKey($this->restoreId)->first()
            ?->forceFill(['status' => 'failed', 'completed_at' => now(), 'error' => str($exception->getMessage())->limit(2000)->toString()])->save();
    }
}
