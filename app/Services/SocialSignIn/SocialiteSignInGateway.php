<?php

declare(strict_types=1);

namespace App\Services\SocialSignIn;

use App\Domain\Identity\Data\SocialProfile;
use App\Domain\Identity\Enums\SocialProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\BitbucketProvider;
use Laravel\Socialite\Two\GithubProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

final class SocialiteSignInGateway implements SocialSignInGateway
{
    public function __construct(private readonly Repository $config) {}

    public function configured(SocialProvider $provider): bool
    {
        $config = $this->settings($provider);

        return filled($config['client_id'] ?? null)
            && filled($config['client_secret'] ?? null)
            && ($provider !== SocialProvider::GitLab || $this->gitlabHost($config) !== null);
    }

    public function redirect(SocialProvider $provider): RedirectResponse
    {
        return $this->driver($provider)->redirect();
    }

    public function profile(SocialProvider $provider): SocialProfile
    {
        try {
            $user = $this->driver($provider)->user();
        } catch (Throwable $exception) {
            throw new SocialSignInFailed($exception->getMessage(), previous: $exception);
        }

        $id = trim((string) $user->getId());
        if ($id === '' || strlen($id) > 191) {
            throw new SocialSignInFailed('The provider returned no usable account id.');
        }
        $email = Str::lower(trim((string) $user->getEmail()));
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
        $name = trim((string) ($user->getName() ?: $user->getNickname()));

        return new SocialProfile($id, $email, $name !== '' ? $name : ($email !== null ? Str::before($email, '@') : $provider->label()));
    }

    private function driver(SocialProvider $provider): AbstractProvider
    {
        if (! $this->configured($provider)) {
            throw new SocialSignInFailed("{$provider->label()} sign-in is not configured.");
        }
        $config = $this->settings($provider);

        $driver = Socialite::buildProvider(match ($provider) {
            SocialProvider::GitHub => GithubProvider::class,
            SocialProvider::GitLab => ConfirmedEmailGitlabProvider::class,
            SocialProvider::Bitbucket => BitbucketProvider::class,
        }, [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect' => route('social.callback', $provider),
        ]);

        if ($driver instanceof ConfirmedEmailGitlabProvider) {
            $driver->setHost($this->gitlabHost($config));
        }

        return $driver;
    }

    /** @return array<string, mixed> */
    private function settings(SocialProvider $provider): array
    {
        $config = $this->config->get('services.'.$provider->value);

        return is_array($config) ? $config : [];
    }

    /**
     * Only a bare HTTPS origin is accepted, so a misconfigured host can't leak tokens over plain HTTP.
     *
     * @param  array<string, mixed>  $config
     */
    private function gitlabHost(array $config): ?string
    {
        $host = rtrim(is_string($config['host'] ?? null) ? $config['host'] : 'https://gitlab.com', '/');
        $parts = parse_url($host);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && filled($parts['host'] ?? null)
            && array_diff(array_keys($parts), ['scheme', 'host', 'port']) === []
                ? $host
                : null;
    }
}
