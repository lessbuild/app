<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\Admin\PlatformAdmins;

final class DeleteFeatureFlag
{
    /**
     * Create a new DeleteFeatureFlag instance.
     *
     * Deletes feature flags.
     *
     * @param  PlatformAdmins  $admins  Records the deletion in the admin trail.
     */
    public function __construct(private readonly PlatformAdmins $admins) {}

    /**
     * Delete a flag once the code no longer asks for it (its key then reads as off).
     *
     * @param  User  $admin
     * @param  FeatureFlag  $flag
     * @return void
     */
    public function handle(User $admin, FeatureFlag $flag): void
    {
        $flag->delete();
        $this->admins->record($admin, 'flag.deleted', "Deleted the feature flag {$flag->key}.");
    }
}
