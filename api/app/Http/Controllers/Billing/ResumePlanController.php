<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\ResumeServiceTier;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ResumePlanController
{
    /**
     * Cancel a scheduled downgrade, so the paid tier carries on.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $service
     * @param  ResumeServiceTier  $resume
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, string $service, ResumeServiceTier $resume): JsonResponse
    {
        $resume->handle($user, $account, $service);

        return response()->json(['redirect' => route('account.billing', ['tab' => $service], false), 'message' => __('Your plan will carry on as before.')]);
    }
}
