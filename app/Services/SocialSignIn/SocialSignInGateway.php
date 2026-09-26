<?php

declare(strict_types=1);

namespace App\Services\SocialSignIn;

use App\Domain\Identity\Data\SocialProfile;
use App\Domain\Identity\Enums\SocialProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** OAuth sign-in providers. Both legs return to the `social.callback` route. */
interface SocialSignInGateway
{
    public function configured(SocialProvider $provider): bool;

    public function redirect(SocialProvider $provider): RedirectResponse;

    /** @throws SocialSignInFailed when the provider rejects the callback or returns an unusable profile */
    public function profile(SocialProvider $provider): SocialProfile;
}
