<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Agency\SaveBranding;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateBrandingController
{
    /**
     * Save the account's white-label branding.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  SaveBranding  $save
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, SaveBranding $save): RedirectResponse
    {
        $data = $request->validate(['brand_name' => ['nullable', 'string', 'max:100'], 'brand_logo_url' => ['nullable', 'string', 'max:500'], 'brand_color' => ['nullable', 'string', 'max:7']]);
        $save->handle($user, $account, $data);

        return to_route('account.clients')->with('status', __('Branding saved.'));
    }
}
