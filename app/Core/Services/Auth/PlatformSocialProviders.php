<?php

namespace App\Core\Services\Auth;

use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\BitbucketProvider;
use Laravel\Socialite\Two\GithubProvider;

/** Owns the providers and callback URL used by the shared Core authentication host. */
final class PlatformSocialProviders
{
    /** @var array<string, class-string<AbstractProvider>> */
    private const DRIVERS = [
        'github' => GithubProvider::class,
        'gitlab' => GitlabPlatformProvider::class,
        'bitbucket' => BitbucketProvider::class,
    ];

    /** @var array<string, string> */
    private const LABELS = [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::DRIVERS);
    }

    /** @return list<array{key: string, name: string, configured: bool}> */
    public function catalog(): array
    {
        return collect(self::LABELS)
            ->map(fn (string $name, string $key): array => [
                'key' => $key,
                'name' => $name,
                'configured' => $this->configured($key),
            ])
            ->values()
            ->all();
    }

    public function supports(string $provider): bool
    {
        return array_key_exists($provider, self::DRIVERS);
    }

    public function configured(string $provider): bool
    {
        if (! $this->supports($provider)) {
            return false;
        }

        $config = config('services.'.$provider, []);

        return is_array($config)
            && filled($config['client_id'] ?? null)
            && filled($config['client_secret'] ?? null)
            && ($provider !== 'gitlab' || $this->gitlabHost($config) !== null);
    }

    public function label(string $provider): string
    {
        abort_unless($this->supports($provider), 404);

        return self::LABELS[$provider];
    }

    public function driver(string $provider): AbstractProvider
    {
        abort_unless($this->configured($provider), 503, __('This social sign-in provider is not configured.'));

        $config = config('services.'.$provider, []);

        $driver = Socialite::buildProvider(self::DRIVERS[$provider], [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect' => route('platform.social.callback', ['provider' => $provider]),
            'scopes' => $config['scopes'] ?? [],
            'guzzle' => $config['guzzle'] ?? [],
        ]);

        if ($driver instanceof GitlabPlatformProvider) {
            $host = $this->gitlabHost($config);
            abort_unless($host !== null, 503, __('The GitLab host must be a valid HTTPS origin.'));
            $driver->setHost($host);
        }

        return $driver;
    }

    /** @param array<string, mixed> $config */
    private function gitlabHost(array $config): ?string
    {
        $host = rtrim((string) ($config['host'] ?? 'https://gitlab.com'), '/');
        $parts = parse_url($host);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && filled($parts['host'] ?? null)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['path'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
                ? $host
                : null;
    }
}
