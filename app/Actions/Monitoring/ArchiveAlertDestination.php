<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Exceptions\StateConflict;
use App\Models\Account;
use App\Models\AlertDestination;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ArchiveAlertDestination
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /** Turn a destination off and archive it; its delivery history stays. */
    public function handle(Account $account, User $actor, AlertDestination $destination, int $version): AlertDestination
    {
        return DB::transaction(function () use ($account, $actor, $destination, $version): AlertDestination {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            Gate::forUser($actor)->authorize('delete', $destination);
            $destination = AlertDestination::forAccount($account)->lockForUpdate()->findOrFail($destination->id);
            StateConflict::unlessVersion($destination->state_version, $version, __('This destination changed. Refresh before trying again.'));
            $destination->forceFill(['enabled' => false]);
            $destination->forceFill(['state_version' => $destination->state_version + 1, 'target_revision' => $destination->target_revision + 1])->save();
            $destination->delete();
            $this->audit->handle(AuditAction::AlertDestinationArchived, $actor, $account->id, [
                'destination' => $destination->name, 'type' => $destination->type->value,
            ]);

            return $destination;
        }, attempts: 3);
    }
}
