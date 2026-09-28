<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\BackupDestination;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DeleteBackupDestination
{
    /**
     * Removes a backup destination that nothing uses.
     *
     * @param  RecordAuditEntry  $audit  Records the removal.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Remove a destination nothing uses. Its bucket isn't touched.
     *
     * @param  User  $actor
     * @param  BackupDestination  $destination
     * @return void
     */
    public function handle(User $actor, BackupDestination $destination): void
    {
        Gate::forUser($actor)->authorize('delete', $destination);
        DB::transaction(function () use ($actor, $destination): void {
            $locked = BackupDestination::query()->lockForUpdate()->findOrFail($destination->id);
            if ($locked->schedules()->exists() || $locked->backups()->exists()) {
                throw ValidationException::withMessages(['destination' => __('Remove its schedules and backups before deleting this destination.')]);
            }
            $locked->delete();
            $this->audit->handle(AuditAction::BackupDestinationDeleted, $actor, $locked->account_id, ['destination' => $locked->name]);
        });
    }
}
