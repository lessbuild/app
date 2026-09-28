<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Enums\ProviderType;
use App\Models\Account;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveProvider
{
    /**
     * Connects or changes a provider.
     *
     * @param  RecordAuditEntry  $audit  Records the change (never the token).
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Connect a provider, or change one. A new token, or a new type, resets the connection health. The type can't change while servers use it.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  array<string, mixed>  $data  validated by ProviderRequest
     * @param  Provider|null  $provider
     * @return Provider
     */
    public function handle(Account $account, User $actor, array $data, ?Provider $provider = null): Provider
    {
        return DB::transaction(function () use ($account, $actor, $data, $provider): Provider {
            Gate::forUser($actor)->authorize($provider === null ? 'create' : 'update', $provider ?? Provider::class);
            $isNew = $provider === null;
            $provider = $isNew ? new Provider : Provider::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($provider->id);
            $type = ProviderType::from((string) $data['type']);
            if (! $isNew && $provider->type !== $type && $provider->hasAttachedResources()) {
                throw ValidationException::withMessages(['type' => __('The type can’t change while servers use this provider.')]);
            }
            $token = is_string($data['token'] ?? null) ? trim($data['token']) : '';
            if ($isNew && $token === '') {
                throw ValidationException::withMessages(['token' => __('Enter the API token.')]);
            }
            $credentialChanged = $isNew || $token !== '' || $provider->type !== $type;
            $description = trim((string) ($data['description'] ?? ''));

            $provider->forceFill([
                'account_id' => $account->id,
                'created_by' => $provider->created_by ?? $actor->id,
                'name' => trim((string) $data['name']),
                'description' => $description !== '' ? $description : null,
                'type' => $type,
                'connection_monitoring_enabled' => (bool) ($data['connection_monitoring_enabled'] ?? true),
                'connection_check_interval_minutes' => (int) ($data['connection_check_interval_minutes'] ?? 1440),
                'connection_failure_threshold' => (int) ($data['connection_failure_threshold'] ?? 1),
            ]);
            if ($token !== '') {
                $provider->token = $token;
            }
            if ($credentialChanged) {
                $provider->forceFill(['connection_status' => 'unchecked', 'connection_checked_at' => null, 'connection_failure_count' => 0]);
            }
            $provider->save();
            $this->audit->handle($isNew ? AuditAction::ProviderCreated : AuditAction::ProviderUpdated, $actor, $account->id, [
                'provider' => $provider->name, 'type' => $type->label(), 'token_changed' => ! $isNew && $token !== '',
            ]);

            return $provider;
        }, attempts: 3);
    }
}
