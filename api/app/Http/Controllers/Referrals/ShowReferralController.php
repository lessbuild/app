<?php

declare(strict_types=1);

namespace App\Http\Controllers\Referrals;

use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

/** `/r/{code}`: an account's refer-a-friend link. */
final class ShowReferralController
{
    /**
     * Remember the code for 30 days and go to sign-up. Unknown codes still go to sign-up, just without the cookie.
     *
     * @param  string  $code
     * @return RedirectResponse
     */
    public function __invoke(string $code): RedirectResponse
    {
        $code = strtolower($code);
        if (Account::query()->where('referral_code', $code)->exists()) {
            Cookie::queue(Cookie::make('bp_referral', $code, 60 * 24 * 30, secure: null, httpOnly: true, sameSite: 'lax'));
        }

        return redirect()->route('register');
    }
}
