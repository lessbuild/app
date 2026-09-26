<?php

namespace App\Modules\Deployer\Actions\Organization;

use App\Modules\Deployer\Models\Organization;

class UpdateOrganizationNotificationPreferencesAction
{
    /**
     * Persist normalized workspace notification categories and recovery preferences.
     *
     * @param  array{categories?: list<string>|null, recoveries: bool|string|int}  $attributes  Validated preferences.
     */
    public function handle(Organization $organization, array $attributes): void
    {
        $organization->update(['notification_preferences' => [
            'categories' => array_values($attributes['categories'] ?? []),
            'recoveries' => (bool) $attributes['recoveries'],
        ]]);
    }
}
