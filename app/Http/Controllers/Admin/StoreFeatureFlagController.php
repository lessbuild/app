<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SaveFeatureFlag;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StoreFeatureFlagController
{
    /**
     * Create a flag, off, and return to the flags.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  SaveFeatureFlag  $save
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, SaveFeatureFlag $save): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/\A[a-z0-9][a-z0-9.-]*\z/', Rule::unique('feature_flags', 'key')],
            'description' => ['required', 'string', 'max:255'],
        ]);
        $save->handle($user, null, ['key' => (string) $data['key'], 'description' => (string) $data['description']]);

        return to_route('admin.flags')->with('status', __('Flag created. It’s off until you turn it on.'));
    }
}
