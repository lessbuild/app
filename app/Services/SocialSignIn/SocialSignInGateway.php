<?php

declare(strict_types=1);

namespace App\Services\SocialSignIn;

use App\Data\Users\SocialProfile;
use App\Enums\SocialProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** OAuth sign-in providers. Both legs return to the `social.callback` route. */
interface SocialSignInGateway
{
    /**
     * Whether sign-in with the provider is set up in this environment.
     */
    public function configured(SocialProvider $provider): bool;

    /**
     * Sends the person to the provider to approve signing in.
     */
    public function redirect(SocialProvider $provider): RedirectResponse;

    /**
     * The person's profile once the provider sends them back. Throws SocialSignInFailed when the provider refused or
     * returned something unusable.
     *
     * @throws SocialSignInFailed when the provider rejects the callback or returns an unusable profile
     */
    public function profile(SocialProvider $provider): SocialProfile;
}
