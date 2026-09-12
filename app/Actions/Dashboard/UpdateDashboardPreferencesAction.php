<?php

namespace App\Actions\Dashboard;

use App\Models\User;

class UpdateDashboardPreferencesAction
{
    /**
     * Replace the selected dashboard widgets while preserving unrelated user preferences.
     *
     * @param  User  $user  Account whose dashboard layout is being changed.
     * @param  array<string, mixed>  $attributes  Validated widget selection attributes.
     */
    public function handle(User $user, array $attributes): void
    {
        $preferences = $user->preferences ?? [];
        $preferences['dashboard_widgets'] = array_values($attributes['widgets'] ?? []);
        $user->update(['preferences' => $preferences]);
    }
}
