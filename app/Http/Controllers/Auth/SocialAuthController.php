<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RegistrationAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialAuthController extends Controller
{
    private const PROVIDER_COLUMNS = [
        'github' => 'github_id',
        'gitlab' => 'gitlab_id',
        'bitbucket' => 'bitbucket_id',
    ];

    /**
     * @return list<string>
     */
    public static function providers(): array
    {
        return array_keys(self::PROVIDER_COLUMNS);
    }

    public function redirect(string $provider): RedirectResponse
    {
        if (! $this->configured($provider)) {
            return redirect()->route('login')->withErrors([
                'social_auth' => __(':provider sign-in has not been configured yet.', [
                    'provider' => ucfirst($provider),
                ]),
            ]);
        }

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider, RegistrationAccess $registration): RedirectResponse
    {
        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('login')->withErrors([
                'social_auth' => __('Unable to sign in with :provider. Please try again.', [
                    'provider' => ucfirst($provider),
                ]),
            ]);
        }

        $providerId = trim((string) $socialUser->getId());
        $email = Str::lower(trim((string) $socialUser->getEmail()));

        if ($providerId === '' || $email === '') {
            return redirect()->route('login')->withErrors([
                'social_auth' => __('Your :provider account must provide an email address.', [
                    'provider' => ucfirst($provider),
                ]),
            ]);
        }

        $providerColumn = self::PROVIDER_COLUMNS[$provider];
        $user = $registration->synchronized(function () use (
            $email,
            $provider,
            $providerColumn,
            $providerId,
            $registration,
            $socialUser,
        ): ?User {
            $user = User::query()->where($providerColumn, $providerId)->first();

            if (! $user) {
                $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            }

            if ($user) {
                if ($user->{$providerColumn} !== $providerId) {
                    $user->forceFill([$providerColumn => $providerId])->save();
                }

                return $user;
            }

            if (! $registration->allowsNewUser()) {
                return null;
            }

            return User::create([
                'name' => $socialUser->getName() ?: $socialUser->getNickname() ?: Str::before($email, '@'),
                'email' => $email,
                $providerColumn => $providerId,
                'auth_type' => $provider,
                'password' => Hash::make(Str::password(40)),
                'password_set_at' => null,
                'email_verified_at' => now(),
            ]);
        });

        if (! $user) {
            return redirect()->route('login')->withErrors([
                'social_auth' => __('No account matches this :provider identity, and registration is closed.', [
                    'provider' => ucfirst($provider),
                ]),
            ]);
        }

        Auth::login($user);
        request()->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function configured(string $provider): bool
    {
        return filled(config("services.{$provider}.client_id"))
            && filled(config("services.{$provider}.client_secret"))
            && filled(config("services.{$provider}.redirect"));
    }
}
