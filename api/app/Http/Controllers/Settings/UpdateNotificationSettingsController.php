<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Monitoring\SaveIssueDigestPreference;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateNotificationSettingsController
{
    /**
     * Turn the issue digest on or off.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  SaveIssueDigestPreference  $save
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, SaveIssueDigestPreference $save): JsonResponse
    {
        $validated = $request->validate(['issue_digest' => ['required', 'boolean']]);
        $save->handle($account, $user, (bool) $validated['issue_digest']);

        return response()->json(['redirect' => route('settings.notifications', [], false), 'message' => __('Email preferences saved.')]);
    }
}
