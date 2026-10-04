<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateGettingStartedEmailsController
{
    /**
     * Turn the welcome and setup-reminder emails on or off.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $user->forceFill(['getting_started_emails' => (bool) $request->validate(['getting_started_emails' => ['required', 'boolean']])['getting_started_emails']])->save();

        return response()->json(['redirect' => route('settings.notifications', [], false), 'message' => __('Email preferences saved.')]);
    }
}
