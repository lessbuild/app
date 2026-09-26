<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Identity\Data\SocialProfile;
use App\Domain\Identity\Enums\SocialProvider;
use App\Services\SocialSignIn\SocialSignInFailed;
use App\Services\SocialSignIn\SocialSignInGateway;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class FakeSocialSignInGateway implements SocialSignInGateway
{
    public ?SocialProfile $profile = null;

    /** @param list<SocialProvider> $configured */
    public function __construct(public array $configured = [SocialProvider::GitHub, SocialProvider::GitLab]) {}

    public function configured(SocialProvider $provider): bool
    {
        return in_array($provider, $this->configured, true);
    }

    public function redirect(SocialProvider $provider): RedirectResponse
    {
        return new RedirectResponse("https://{$provider->value}.test/authorize");
    }

    public function profile(SocialProvider $provider): SocialProfile
    {
        return $this->profile ?? throw new SocialSignInFailed('No profile queued.');
    }
}
