<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SaveFeatureFlag;
use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UpdateFeatureFlagController
{
    /**
     * Change a flag's state, description and accounts (one account ID a line) and return to the flags.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $flag
     * @param  SaveFeatureFlag  $save
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, string $flag, SaveFeatureFlag $save): RedirectResponse
    {
        $data = $request->validate([
            'state' => ['required', Rule::in(FeatureFlag::STATES)], 'description' => ['required', 'string', 'max:255'], 'account_ids' => ['nullable', 'string', 'max:20000'],
        ]);
        $accounts = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($data['account_ids'] ?? '')) ?: [])));
        $save->handle($user, FeatureFlag::query()->findOrFail((int) $flag), ['state' => (string) $data['state'], 'description' => (string) $data['description'], 'account_ids' => $accounts]);

        return to_route('admin.flags')->with('status', __('Flag saved.'));
    }
}
