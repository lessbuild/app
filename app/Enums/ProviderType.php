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

    /** What the account uses it for. */
    public function purpose(): string
    {
        return match ($this) {
            self::DigitalOcean, self::Hetzner, self::Vultr => __('Servers'),
            self::Cloudflare => __('DNS'),
            self::GitHub, self::GitLab, self::Bitbucket => __('Git repositories'),
        };
    }

    public function hostsServers(): bool
    {
        return in_array($this, [self::DigitalOcean, self::Hetzner, self::Vultr], true);
    }

    public function isSourceControl(): bool
    {
        return in_array($this, [self::GitHub, self::GitLab, self::Bitbucket], true);
    }

    /** @return list<self> */
    public static function serverHosts(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->hostsServers()));
    }
}
