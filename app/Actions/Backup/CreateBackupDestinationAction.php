<?php

namespace App\Actions\Backup;

use App\Models\BackupDestination;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

class CreateBackupDestinationAction
{
    /**
     * Persist an encrypted destination and its generated repository password for a workspace.
     *
     * @param  array<string, mixed>  $attributes  Validated destination connection attributes.
     */
    public function handle(Organization $organization, User $actor, array $attributes): BackupDestination
    {
        return $organization->backupDestinations()->create([
            ...$attributes,
            'created_by' => $actor->id,
            'repository_password' => Str::password(40),
            'is_active' => true,
        ]);
    }
}
