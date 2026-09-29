<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Billing\ResumeServiceTier;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class ResumePlanController
{
    /**
     * Cancel a scheduled downgrade, so the paid tier carries on.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $service
     * @param  ResumeServiceTier  $resume
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, string $service, ResumeServiceTier $resume): RedirectResponse
    {
        $resume->handle($user, $account, $service);

        return to_route('account.billing', ['tab' => $service])->with('status', __('Your plan will carry on as before.'));
    }
}
