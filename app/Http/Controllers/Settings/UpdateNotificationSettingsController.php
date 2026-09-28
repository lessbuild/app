<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Monitoring\SaveIssueDigestPreference;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateNotificationSettingsController
{
    /**
     * Turns the issue digest on or off.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  SaveIssueDigestPreference  $save
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, SaveIssueDigestPreference $save): RedirectResponse
    {
        $validated = $request->validate(['issue_digest' => ['required', 'boolean']]);
        $save->handle($account, $user, (bool) $validated['issue_digest']);

        return to_route('settings.notifications')->with('status', __('Email preferences saved.'));
    }
}
