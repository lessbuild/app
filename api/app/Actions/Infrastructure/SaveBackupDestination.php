<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\BackupDestination;
use App\Models\User;
use App\Support\Infrastructure\BackupDestinationPresets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SaveBackupDestination
{
    /**
     * Create a new SaveBackupDestination instance.
     *
     * Creates or changes a backup destination.
     *
     * @param  RecordAuditEntry  $audit  Records the change (never the keys).
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Add a destination (with a generated restic password), or change one. Blank keys keep the stored ones; a change of
     * where or how it connects clears its verified state. The bucket and prefix can't move once backups use them, because
     * the snapshots live there.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  array{name: string, storage_provider: string, endpoint?: string|null, bucket: string, region: string, access_key?: string|null, secret_key?: string|null, path_prefix: string}  $data
     * @param  BackupDestination|null  $destination
     * @return BackupDestination
     */
    public function handle(Account $account, User $actor, array $data, ?BackupDestination $destination = null): BackupDestination
    {
        return DB::transaction(function () use ($account, $actor, $data, $destination): BackupDestination {
            $isNew = $destination === null;
            Gate::forUser($actor)->authorize($isNew ? 'create' : 'update', $destination ?? [BackupDestination::class, $account]);
            $destination = $isNew ? new BackupDestination : BackupDestination::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($destination->id);
            $endpoint = BackupDestinationPresets::endpoint($data['storage_provider'], trim($data['region']), $data['endpoint'] ?? null);
            if (! is_string($endpoint) || ! str_starts_with($endpoint, 'https://')) {
                throw ValidationException::withMessages(['endpoint' => __('Enter the storage’s HTTPS endpoint.')]);
            }
            $prefix = trim($data['path_prefix'], '/');
            if (! $isNew && ($destination->bucket !== $data['bucket'] || $destination->path_prefix !== $prefix || rtrim($destination->endpoint, '/') !== rtrim($endpoint, '/')) && $destination->backups()->exists()) {
                throw ValidationException::withMessages(['bucket' => __('The endpoint, bucket and prefix can’t change once backups are stored there. Add a new destination instead.')]);
            }
            $keys = array_filter(['access_key' => trim((string) ($data['access_key'] ?? '')), 'secret_key' => trim((string) ($data['secret_key'] ?? ''))], fn (string $key): bool => $key !== '');
            if ($isNew && count($keys) < 2) {
                throw ValidationException::withMessages(['access_key' => __('Enter the access key and secret.')]);
            }
            $destination->forceFill([
                'account_id' => $account->id, 'created_by' => $destination->created_by ?? $actor->id, 'name' => trim($data['name']),
                'storage_provider' => $data['storage_provider'], 'endpoint' => rtrim($endpoint, '/'), 'bucket' => $data['bucket'],
                'region' => trim($data['region']), 'path_prefix' => $prefix, ...$keys,
            ]);
            if ($isNew) {
                $destination->repository_password = Str::password(40);
            }
            if ($destination->isDirty(['endpoint', 'bucket', 'region', 'path_prefix', 'access_key', 'secret_key'])) {
                $destination->forceFill(['last_verified_at' => null, 'last_error' => null]);
            }
            $destination->save();
            $this->audit->handle($isNew ? AuditAction::BackupDestinationCreated : AuditAction::BackupDestinationUpdated, $actor, $account->id, ['destination' => $destination->name]);

            return $destination;
        });
    }
}
