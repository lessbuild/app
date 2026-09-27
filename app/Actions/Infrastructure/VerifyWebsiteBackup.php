<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\VerifyWebsiteBackup as RunVerification;
use App\Models\BackupVerification;
use App\Models\User;
use App\Models\WebsiteBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class VerifyWebsiteBackup
{
    /** Check a completed backup restores, in a temporary database on the same server. The live website isn't touched. */
    public function handle(User $actor, WebsiteBackup $backup): BackupVerification
    {
        Gate::forUser($actor)->authorize('backUp', $backup->website);

        return DB::transaction(function () use ($actor, $backup): BackupVerification {
            $locked = WebsiteBackup::query()->lockForUpdate()->findOrFail($backup->id);
            if (! $locked->isRestorable()) {
                throw ValidationException::withMessages(['backup' => __('Only completed backups can be verified.')]);
            }
            if ($locked->verifications()->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['backup' => __('This backup is already being verified.')]);
            }
            $verification = new BackupVerification;
            $verification->forceFill(['website_backup_id' => $locked->id, 'requested_by' => $actor->id, 'snapshot_id' => strtolower((string) $locked->snapshot_id), 'status' => 'queued'])->save();
            RunVerification::dispatch($verification->id)->afterCommit();

            return $verification;
        });
    }
}
