<?php

declare(strict_types=1);

namespace App\Enums;

/** The services an account can connect: clouds that host servers, Cloudflare for DNS, and Git hosts for Deploy. */
enum ProviderType: string
{
    case DigitalOcean = 'digitalocean';
    case Hetzner = 'hetzner';
    case Vultr = 'vultr';
    case Cloudflare = 'cloudflare';
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';

    /**
     * The provider's product name.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::DigitalOcean => 'DigitalOcean',
            self::Hetzner => 'Hetzner Cloud',
            self::Vultr => 'Vultr',
            self::Cloudflare => 'Cloudflare',
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
        };
    }

    /**
     * What the account uses it for.
     *
     * @return string
     */
    public function purpose(): string
    {
        return match ($this) {
            self::DigitalOcean, self::Hetzner, self::Vultr => __('Servers'),
            self::Cloudflare => __('DNS'),
            self::GitHub, self::GitLab, self::Bitbucket => __('Git repositories'),
        };
    }

    /**
     * Whether we can create servers with this provider's API.
     *
     * @return bool
     */
    public function hostsServers(): bool
    {
        return in_array($this, [self::DigitalOcean, self::Hetzner, self::Vultr], true);
    }

    /**
     * Whether the provider hosts Git repositories we deploy from.
     *
     * @return bool
     */
    public function isSourceControl(): bool
    {
        return in_array($this, [self::GitHub, self::GitLab, self::Bitbucket], true);
    }

    /**
     * The Git host repositories are cloned from, for source-control providers.
     *
     * @return string|null
     */
    public function repositoryHost(): ?string
    {
        return match ($this) {
            self::GitHub => 'github.com',
            self::GitLab => 'gitlab.com',
            self::Bitbucket => 'bitbucket.org',
            default => null,
        };
    }

    /**
     * The username that goes with the token for Git over HTTPS.
     *
     * @return string|null
     */
    public function repositoryCredentialUsername(): ?string
    {
        return match ($this) {
            self::GitHub => 'x-access-token',
            self::GitLab => 'oauth2',
            self::Bitbucket => 'x-token-auth',
            default => null,
        };
    }

    /**
     * The providers that can host servers, for the server creation form.
     *
     * @return list<self>
     */
    public static function serverHosts(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->hostsServers()));
    }
}
