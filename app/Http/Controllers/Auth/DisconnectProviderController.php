<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Users\DisconnectSocialIdentity;
use App\Enums\SocialProvider;
use App\Exceptions\IdentityRuleViolation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DisconnectProviderController
{
    public function __invoke(#[CurrentUser] User $user, SocialProvider $provider, DisconnectSocialIdentity $disconnect): RedirectResponse
    {
        try {
            $disconnected = $disconnect->handle($user, $provider);
        } catch (IdentityRuleViolation $violation) {
            return to_route('settings.security')->withErrors([$violation->field => $violation->getMessage()], 'social');
        }

        return to_route('settings.security')->with('status', $disconnected ? 'social-disconnected' : 'social-not-connected');
    }
}
