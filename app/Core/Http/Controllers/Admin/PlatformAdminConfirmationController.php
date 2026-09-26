<?php

namespace App\Core\Http\Controllers\Admin;

use App\Core\Http\Middleware\EnsurePlatformAdmin;
use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\VerifyPlatformTwoFactorCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PlatformAdminConfirmationController
{
    public function show(Request $request): View
    {
        $user = $request->user('platform');

        return view('core::admin.confirm', [
            'canUsePassword' => $user->hasPassword(),
            'canUseCode' => $user->twoFactorEnabled(),
        ]);
    }

    /** Accept the account password or an authenticator/recovery code, then return to the requested admin page. */
    public function store(Request $request, VerifyPlatformTwoFactorCode $codes): RedirectResponse
    {
        /** @var PlatformUser $user */
        $user = $request->user('platform');
        $data = $request->validate([
            'password' => ['nullable', 'string', 'max:1024', 'required_without:code'],
            'code' => ['nullable', 'string', 'max:64', 'required_without:password'],
        ]);

        $confirmed = filled($data['password'] ?? null)
            ? $user->hasPassword() && Hash::check((string) $data['password'], (string) $user->password)
            : $codes->handle($user, (string) $data['code']);
        if (! $confirmed) {
            throw ValidationException::withMessages([
                filled($data['password'] ?? null) ? 'password' : 'code' => __('That did not match. Try again.'),
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put(EnsurePlatformAdmin::SESSION_KEY, ['user' => (string) $user->getKey(), 'at' => now()->getTimestamp()]);
        $intended = $request->session()->pull(EnsurePlatformAdmin::INTENDED_KEY);

        return is_string($intended) && str_starts_with($intended, url('/admin'))
            ? redirect()->to($intended)
            : redirect()->route('core.admin.index');
    }
}
