<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UpdateScimSettings
{
    /**
     * Create a new UpdateScimSettings instance.
     *
     * @param  RecordAuditEntry  $audit  Records the change.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Turn SCIM provisioning on with a new token (replacing any old one, returned once), change the role new people
     * get, or turn it off.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  string  $change  "token", "role" or "off"
     * @param  string|null  $role  the role for "role"
     * @return string|null the new token for "token"
     */
    public function handle(User $actor, Account $account, string $change, ?string $role = null): ?string
    {
        Gate::forUser($actor)->authorize('update', $account);
        $token = null;
        match ($change) {
            'token' => $account->forceFill(['scim_token_hash' => hash('sha256', $token = 'scim_'.Str::random(48))]),
            'off' => $account->forceFill(['scim_token_hash' => null]),
            'role' => $account->forceFill(['scim_default_role' => in_array($role, [AccountRole::Viewer->value, AccountRole::Member->value, AccountRole::Admin->value], true)
                ? $role : throw ValidationException::withMessages(['scim_default_role' => __('Choose viewer, member or admin.')])]),
            default => throw ValidationException::withMessages(['change' => __('Unknown change.')]),
        };
        $account->save();
        $this->audit->handle(AuditAction::ScimChanged, $actor, $account->id, ['change' => match ($change) {
            'token' => __('new token'), 'off' => __('turned off'), default => __('new people join as :role', ['role' => $role]),
        }]);

        return $token;
    }
}
