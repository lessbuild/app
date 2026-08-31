<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\SocialAuthController;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

class UsersController extends Controller
{
    public function index(Request $request): View
    {
        $connected = $request->user()->connectedSocialProviders();

        return view('scenes.users.index', [
            'recentAccountEvents' => $request->user()
                ->accountEvents()
                ->select(['id', 'event', 'category', 'created_at'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(5)
                ->get(),
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

    public function updateProfile(Request $request, ActivityRecorder $activity): RedirectResponse
    {
        $request->merge([
            'email' => Str::lower((string) $request->input('email')),
        ]);
        $emailChanged = $request->user()->email !== $request->input('email');
        $currentPasswordRules = $emailChanged && $request->user()->hasLocalPassword()
            ? ['required', 'current_password']
            : ['exclude'];

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
            'current_password' => $currentPasswordRules,
        ]);

        if ($emailChanged) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ])->save();

        if ($emailChanged && $request->user()->hasLocalPassword()) {
            Auth::guard('web')->logoutOtherDevices($validated['current_password']);
            $request->session()->regenerate();
        }

        $activity->recordAccount(
            $request->user(),
            $emailChanged
                ? 'Account email address was changed and requires verification.'
                : 'Account profile was updated.',
        );

        $response = back()->with('profile_status', __('Profile updated.'));
        if (! $emailChanged) {
            return $response;
        }

        try {
            $request->user()->sendEmailVerificationNotification();

            return $response->with('status', 'verification-link-sent');
        } catch (Throwable $exception) {
            report($exception);

            return $response->with('verification_error', __(
                'The email address was updated, but the verification message could not be sent. Try sending it again below.',
            ));
        }
    }

    public function updatePassword(Request $request, ActivityRecorder $activity): RedirectResponse
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
        $activity->recordAccount($request->user(), 'Account password was changed.');

        return back()->with('password_status', __('Password updated.'));
    }

    public function revokeOtherSessions(Request $request, ActivityRecorder $activity): RedirectResponse
    {
        $validated = $request->validateWithBag('sessions', [
            'current_password' => ['required', 'current_password'],
        ]);

        Auth::guard('web')->logoutOtherDevices($validated['current_password']);
        $request->session()->regenerate();
        $activity->recordAccount($request->user(), 'Other browser sessions were logged out.');

        return back()->with('sessions_status', __('Other browser sessions logged out.'));
    }

    public function disconnectSocial(Request $request, string $provider, ActivityRecorder $activity): RedirectResponse
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

        if ($result === 'disconnected') {
            $activity->recordAccount($request->user(), $this->socialProviderName($provider).' sign-in was disconnected.');
        }

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
