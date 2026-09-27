<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DeleteProvider
{
    /**
     * Disconnects a provider nothing depends on.
     *
     * @param  RecordAuditEntry  $audit  Records it.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /** Remove a provider that nothing uses any more. Its credential is kept (soft-deleted) for the audit trail but never used again. */
    public function handle(Account $account, User $actor, Provider $provider): void
    {
        DB::transaction(function () use ($account, $actor, $provider): void {
            Gate::forUser($actor)->authorize('delete', $provider);
            $provider = Provider::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($provider->id);
            if ($provider->hasAttachedResources()) {
                throw ValidationException::withMessages(['provider' => __('Delete this provider’s servers first.')]);
            }
            $provider->delete();
            $this->audit->handle(AuditAction::ProviderDeleted, $actor, $account->id, ['provider' => $provider->name, 'type' => $provider->type->label()]);
        }, attempts: 3);
    }
}
