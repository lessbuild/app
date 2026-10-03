<?php

declare(strict_types=1);

namespace App\Services\SocialSignIn;

use App\Data\Users\SocialProfile;
use App\Enums\SocialProvider;
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
    /**
     * Create a new SocialiteSignInGateway instance.
     *
     * Signs people in through Socialite.
     *
     * @param  Repository  $config  Holds each provider's client credentials under `services.{provider}`.
     */
    public function __construct(private readonly Repository $config) {}

    /**
     * Determine whether the provider has client credentials (and, for GitLab, a valid host) in this environment.
     *
     * @param  SocialProvider  $provider
     * @return bool
     */
    public function configured(SocialProvider $provider): bool
    {
        $config = $this->settings($provider);

        return filled($config['client_id'] ?? null)
            && filled($config['client_secret'] ?? null)
            && ($provider !== SocialProvider::GitLab || $this->gitlabHost($config) !== null);
    }

    /**
     * Redirect to the provider's authorisation page.
     *
     * @param  SocialProvider  $provider
     * @return RedirectResponse
     */
    public function redirect(SocialProvider $provider): RedirectResponse
    {
        return $this->driver($provider)->redirect();
    }

    /**
     * Read the person's profile from the provider's callback, with a usable ID, a valid email or none, and a display
     * name falling back to the email's local part. Anything the provider gets wrong becomes SocialSignInFailed.
     *
     * @param  SocialProvider  $provider
     * @return SocialProfile
     */
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

    /**
     * Build a Socialite driver for the provider, pointed at our callback. Unconfigured providers throw.
     *
     * @param  SocialProvider  $provider
     * @return AbstractProvider
     */
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

    /**
     * Get the provider's configuration, or none.
     *
     * @param  SocialProvider  $provider
     * @return array<string, mixed>
     */
    private function settings(SocialProvider $provider): array
    {
        $config = $this->config->get('services.'.$provider->value);

        return is_array($config) ? $config : [];
    }

    /**
     * Get the configured GitLab host. Only a bare HTTPS origin is accepted, so a misconfigured host can't leak tokens
     * over plain HTTP.
     *
     * @param  array<string, mixed>  $config
     * @return string|null
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
