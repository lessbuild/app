<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\DeleteFeatureFlag;
use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteFeatureFlagController
{
    /**
     * Delete a flag and return to the flags.
     *
     * @param  User  $user
     * @param  string  $flag
     * @param  DeleteFeatureFlag  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, string $flag, DeleteFeatureFlag $delete): RedirectResponse
    {
        $delete->handle($user, FeatureFlag::query()->findOrFail((int) $flag));

        return to_route('admin.flags')->with('status', __('Flag deleted.'));
    }
}
