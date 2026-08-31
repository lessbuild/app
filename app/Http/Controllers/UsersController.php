<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\SocialAuthController;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UsersController extends Controller
{
    public function index(Request $request): View
    {
        $connected = $request->user()->connectedSocialProviders();

        return view('scenes.users.index', [
            'socialProviders' => collect(User::SOCIAL_PROVIDER_COLUMNS)
                ->keys()
                ->map(fn (string $provider): array => [
                    'key' => $provider,
                    'name' => $this->socialProviderName($provider),
                    'connected' => in_array($provider, $connected, true),
                    'configured' => SocialAuthController::configured($provider),
                    'can_disconnect' => $request->user()->hasLocalPassword() || count($connected) > 1,
                ]),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => Str::lower((string) $request->input('email')),
        ]);

        $validated = $request->validateWithBag('profile', [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($request->user()->id),
            ],
        ]);

        if ($request->user()->email !== $validated['email']) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->fill($validated)->save();

        return back()->with('profile_status', __('Profile updated.'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $currentPasswordRules = $request->user()->hasLocalPassword()
            ? ['required', 'current_password']
            : ['nullable'];

        $validated = $request->validateWithBag('password', [
            'current_password' => $currentPasswordRules,
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
            'password_set_at' => now(),
        ]);
        Auth::guard('web')->logoutOtherDevices($validated['password']);

        $request->session()->regenerate();

        return back()->with('password_status', __('Password updated.'));
    }

    public function revokeOtherSessions(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('sessions', [
            'current_password' => ['required', 'current_password'],
        ]);

        Auth::guard('web')->logoutOtherDevices($validated['current_password']);
        $request->session()->regenerate();

        return back()->with('sessions_status', __('Other browser sessions logged out.'));
    }

    public function disconnectSocial(Request $request, string $provider): RedirectResponse
    {
        $result = DB::transaction(function () use ($request, $provider): string {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $column = User::SOCIAL_PROVIDER_COLUMNS[$provider];
            $connected = $user->connectedSocialProviders();

            if (! in_array($provider, $connected, true)) {
                return 'missing';
            }

            if (! $user->hasLocalPassword() && count($connected) === 1) {
                return 'last_method';
            }

            $remaining = array_values(array_diff($connected, [$provider]));
            $user->forceFill([
                $column => null,
                'auth_type' => $user->auth_type === $provider
                    ? ($remaining[0] ?? null)
                    : $user->auth_type,
            ])->save();

            return 'disconnected';
        });

        return match ($result) {
            'disconnected' => back()->with('social_status', __(':provider disconnected.', [
                'provider' => $this->socialProviderName($provider),
            ])),
            'last_method' => back()->with('social_error', __('Set a local password before disconnecting your only sign-in method.')),
            default => back()->with('social_status', __('That social account is not connected.')),
        };
    }

    private function socialProviderName(string $provider): string
    {
        return match ($provider) {
            'github' => 'GitHub',
            'gitlab' => 'GitLab',
            'bitbucket' => 'Bitbucket',
        };
    }
}
