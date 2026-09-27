<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Monitoring\SaveIssueDigestPreference;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateNotificationSettingsController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, SaveIssueDigestPreference $save): RedirectResponse
    {
        $validated = $request->validate(['issue_digest' => ['required', 'boolean']]);
        $account = $user->currentAccount;
        abort_if($account === null, 404);
        $save->handle($account, $user, (bool) $validated['issue_digest']);

        return to_route('settings.notifications')->with('status', __('Email preferences saved.'));
    }
}
