<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Identity\Enums\SocialProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Queries\SecuritySettingsQuery;
use App\Services\SocialSignIn\SocialSignInGateway;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;

final class SecurityController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, SecuritySettingsQuery $query, SocialSignInGateway $gateway): View
    {
        // Fortify flashes these statuses right after codes are created; that is the only time they are shown.
        $reveal = in_array($request->session()->get('status'), [Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED, Fortify::RECOVERY_CODES_GENERATED], true);

        $security = $query->handle($user, $reveal);
        $providers = array_map(fn (SocialProvider $provider): array => [
            'provider' => $provider,
            'configured' => $gateway->configured($provider),
            'identity' => array_find($security->socialIdentities, fn ($identity): bool => $identity->provider === $provider),
        ], SocialProvider::cases());

        return view('settings.security', ['user' => $user, 'security' => $security, 'providers' => $providers]);
    }
}
