<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AlertDestinationType;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\AlertDestination;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class RotateAlertDestinationSecret
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /** Replace a signed webhook's signing key. Deliveries still queued are cancelled, since they were signed for the old key. */
    public function handle(Account $account, User $actor, AlertDestination $destination, int $version): AlertDestination
    {
        return DB::transaction(function () use ($account, $actor, $destination, $version): AlertDestination {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            Gate::forUser($actor)->authorize('update', $account);
            $destination = AlertDestination::forAccount($account)->lockForUpdate()->findOrFail($destination->id);
            abort_unless($destination->state_version === $version, 409, __('This destination changed. Refresh before trying again.'));
            abort_unless($destination->type === AlertDestinationType::Webhook, 409, __('Only signed webhooks have a signing key.'));
            $destination->forceFill(['signing_secret' => Str::random(64)]);
            $destination->forceFill(['state_version' => $destination->state_version + 1, 'target_revision' => $destination->target_revision + 1])->save();
            $this->audit->handle(AuditAction::AlertDestinationRotated, $actor, $account->id, [
                'destination' => $destination->name, 'type' => $destination->type->value,
            ]);

            return $destination;
        }, attempts: 3);
    }
}
