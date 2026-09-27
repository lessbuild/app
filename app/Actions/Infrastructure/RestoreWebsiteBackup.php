<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Jobs\Infrastructure\RestoreWebsiteBackup as RunRestore;
use App\Models\BackupRestore;
use App\Models\User;
use App\Models\WebsiteBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RestoreWebsiteBackup
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /** Put a completed backup back over the live website, one restore at a time. */
    public function handle(User $actor, WebsiteBackup $backup): BackupRestore
    {
        Gate::forUser($actor)->authorize('restore', $backup->website);

        return DB::transaction(function () use ($actor, $backup): BackupRestore {
            $locked = WebsiteBackup::query()->lockForUpdate()->findOrFail($backup->id);
            if (! $locked->isRestorable()) {
                throw ValidationException::withMessages(['backup' => __('Only completed backups can be restored.')]);
            }
            if (BackupRestore::query()->whereIn('status', ['queued', 'running'])->whereIn('website_backup_id', WebsiteBackup::query()->where('website_id', $locked->website_id)->select('id'))->exists()) {
                throw ValidationException::withMessages(['backup' => __('A restore is already in progress for this website.')]);
            }
            $restore = new BackupRestore;
            $restore->forceFill(['website_backup_id' => $locked->id, 'requested_by' => $actor->id, 'status' => 'queued'])->save();
            $this->audit->handle(AuditAction::WebsiteBackupRestored, $actor, $locked->website->account_id, [
                'website' => $locked->website->name, 'date' => $locked->completed_at?->toDateTimeString() ?? '',
            ]);
            RunRestore::dispatch($restore->id)->afterCommit();

            return $restore;
        });
    }
}
