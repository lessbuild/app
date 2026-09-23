<?php

namespace App\Modules\Deployer\Actions\Backup;

use App\Modules\Deployer\Exceptions\BackupDestinationUpdateException;
use App\Modules\Deployer\Models\BackupDestination;
use App\Modules\Deployer\Models\WebsiteBackup;
use App\Modules\Deployer\Services\BackupDestinationCatalog;
use Illuminate\Support\Facades\DB;

class UpdateBackupDestinationAction
{
    public function __construct(private readonly BackupDestinationCatalog $destinations) {}

    /**
     * Update connection details while preserving the generated repository password and successful snapshot locations.
     *
     * @param  array<string, mixed>  $attributes  Validated destination values; blank credentials mean keep the existing value.
     * @return BackupDestination The updated, unverified destination.
     *
     * @throws BackupDestinationUpdateException When an active backup exists or a retained snapshot would be relocated.
     */
    public function handle(BackupDestination $destination, array $attributes): BackupDestination
    {
        $attributes = $this->destinations->normalize($attributes);

        return DB::transaction(function () use ($destination, $attributes): BackupDestination {
            $locked = BackupDestination::query()->lockForUpdate()->findOrFail($destination->id);
            if ($locked->backups()->whereIn('status', [WebsiteBackup::STATUS_QUEUED, WebsiteBackup::STATUS_RUNNING])->exists()) {
                throw new BackupDestinationUpdateException('Wait for active backups to finish before editing this destination.');
            }

            $locationFields = ['endpoint', 'bucket', 'region', 'path_prefix'];
            $locationChanged = collect($locationFields)->contains(
                fn (string $field): bool => (string) $locked->{$field} !== (string) ($attributes[$field] ?? ''),
            );
            if ($locationChanged && $locked->backups()->whereNotNull('snapshot_id')->exists()) {
                throw new BackupDestinationUpdateException('Create a new destination instead of moving one that contains retained snapshots.');
            }

            foreach (['access_key', 'secret_key'] as $credential) {
                if (! filled($attributes[$credential] ?? null)) {
                    unset($attributes[$credential]);
                }
            }

            $locked->update([
                ...$attributes,
                'last_verified_at' => null,
                'last_error' => null,
            ]);

            return $locked->refresh();
        });
    }
}
