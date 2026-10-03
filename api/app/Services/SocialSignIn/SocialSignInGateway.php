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
     * Determine whether sign-in with the provider is set up in this environment.
     *
     * @param  SocialProvider  $provider
     * @return bool
     */
    public function configured(SocialProvider $provider): bool;

    /**
     * Send the person to the provider to approve signing in.
     *
     * @param  SocialProvider  $provider
     * @return RedirectResponse
     */
    public function redirect(SocialProvider $provider): RedirectResponse;

    /**
     * Read the person's profile once the provider sends them back. Throws SocialSignInFailed when the provider refused
     * or returned something unusable.
     *
     * @param  SocialProvider  $provider
     * @return SocialProfile
     *
     * @throws SocialSignInFailed when the provider rejects the callback or returns an unusable profile
     */
    public function profile(SocialProvider $provider): SocialProfile;
}
