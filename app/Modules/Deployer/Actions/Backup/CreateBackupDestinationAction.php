<?php

namespace App\Modules\Deployer\Actions\Backup;

use App\Modules\Deployer\Models\BackupDestination;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\BackupDestinationCatalog;
use Illuminate\Support\Str;

class CreateBackupDestinationAction
{
    public function __construct(private readonly BackupDestinationCatalog $destinations) {}

    /**
     * Persist an encrypted destination and its generated repository password for a workspace.
     *
     * @param  array<string, mixed>  $attributes  Validated destination connection attributes.
     */
    public function handle(Organization $organization, User $actor, array $attributes): BackupDestination
    {
        $attributes = $this->destinations->normalize($attributes);

        return $organization->backupDestinations()->create([
            ...$attributes,
            'created_by' => $actor->id,
            'repository_password' => Str::password(40),
            'is_active' => true,
        ]);
    }
}
