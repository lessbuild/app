<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * `POST /email/getting-started/{user}/stop?expires=&signature=`: the signed link in getting-started emails. A mail
 * client's one-click unsubscribe (RFC 8058) posts here directly; people open the same address in the app, which
 * asks first and then posts.
 */
final class StopGettingStartedEmailsController
{
    /**
     * Stop the person's getting-started emails.
     *
     * @param  string  $user
     * @return JsonResponse
     */
    public function __invoke(string $user): JsonResponse
    {
        User::query()->findOrFail($user)->forceFill(['getting_started_emails' => false])->save();

        return response()->json(['message' => __('You won’t get getting-started emails again. You can turn them back on in your notification settings.')]);
    }
}
