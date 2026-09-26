<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\Fortify\CreateNewUser;
use App\Auth\Fortify\ResetUserPassword;
use App\Auth\Fortify\UpdateUserPassword;
use App\Auth\Fortify\UpdateUserProfileInformation;
use App\Auth\Listeners\RecordSignInActivity;
use App\Domain\Identity\Enums\SocialProvider;
use App\Domain\Identity\Models\User;
use App\Services\SocialSignIn\SocialSignInGateway;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::loginView(fn (): View => view('auth.login', ['socialProviders' => $this->configuredProviders()]));
        Fortify::registerView(fn (): View => view('auth.register', ['socialProviders' => $this->configuredProviders()]));
        Fortify::requestPasswordResetLinkView(fn (): View => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request): View => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn (): View => view('auth.verify-email'));
        Fortify::twoFactorChallengeView(fn (): View => view('auth.two-factor-challenge'));
        // Passwordless users confirm with a passkey or by signing in again with a provider they connected.
        Fortify::confirmPasswordView(fn (Request $request): View => view('auth.confirm-password', [
            'socialProviders' => $request->user() instanceof User
                ? array_values(array_filter(
                    $this->configuredProviders(),
                    fn (SocialProvider $provider): bool => $request->user()->socialIdentities()->where('provider', $provider)->exists(),
                ))
                : [],
        ]));

        Event::listen(Login::class, [RecordSignInActivity::class, 'login']);
        Event::listen(Failed::class, [RecordSignInActivity::class, 'failed']);
        Event::listen(TwoFactorAuthenticationFailed::class, [RecordSignInActivity::class, 'twoFactorFailed']);

        RateLimiter::for('login', function (Request $request): Limit {
            $throttleKey = Str::transliterate(Str::lower($request->string(Fortify::username())->toString()).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->session()->get('login.id')));
        RateLimiter::for('passkeys', function (Request $request): Limit {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by((is_string($credentialId) && $credentialId !== '' ? $credentialId : $request->session()->getId()).'|'.$request->ip());
        });
    }

    /** @return list<SocialProvider> */
    private function configuredProviders(): array
    {
        $gateway = $this->app->make(SocialSignInGateway::class);

        return array_values(array_filter(SocialProvider::cases(), $gateway->configured(...)));
    }
}
