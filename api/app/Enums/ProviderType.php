<?php

declare(strict_types=1);

namespace App\Enums;

/** The services an account can connect: clouds that host servers, Cloudflare for DNS, and Git hosts for Deploy. */
enum ProviderType: string
{
    case DigitalOcean = 'digitalocean';
    case Hetzner = 'hetzner';
    case Vultr = 'vultr';
    case Linode = 'linode';
    case Lightsail = 'lightsail';
    case Ec2 = 'ec2';
    case GoogleCompute = 'gce';
    case Azure = 'azure';
    case Ovh = 'ovh';
    case Scaleway = 'scaleway';
    case UpCloud = 'upcloud';
    case Cloudflare = 'cloudflare';
    case HetznerDns = 'hetzner_dns';
    case Route53 = 'route53';
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';

    /**
     * Get the provider's product name.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::DigitalOcean => 'DigitalOcean',
            self::Hetzner => 'Hetzner Cloud',
            self::Vultr => 'Vultr',
            self::Linode => 'Linode (Akamai)',
            self::Lightsail => 'AWS Lightsail',
            self::Ec2 => 'AWS EC2',
            self::GoogleCompute => 'Google Compute Engine',
            self::Azure => 'Microsoft Azure',
            self::Ovh => 'OVHcloud',
            self::Scaleway => 'Scaleway',
            self::UpCloud => 'UpCloud',
            self::Cloudflare => 'Cloudflare',
            self::HetznerDns => 'Hetzner DNS',
            self::Route53 => 'AWS Route 53',
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
        };
    }

    /**
     * Describe what the account uses the provider for.
     *
     * @return string
     */
    public function purpose(): string
    {
        return match ($this) {
            self::DigitalOcean, self::Hetzner, self::Vultr, self::Linode, self::Lightsail, self::Ec2, self::GoogleCompute, self::Azure, self::Ovh, self::Scaleway, self::UpCloud => __('Servers'),
            self::Cloudflare, self::HetznerDns, self::Route53 => __('DNS'),
            self::GitHub, self::GitLab, self::Bitbucket => __('Git repositories'),
        };
    }

    /**
     * Determine whether the provider can manage a domain's DNS records: Cloudflare, DigitalOcean, Hetzner DNS and
     * Route 53.
     *
     * @return bool
     */
    public function managesDns(): bool
    {
        return in_array($this, [self::Cloudflare, self::DigitalOcean, self::HetznerDns, self::Route53], true);
    }

    /**
     * Determine whether we can create servers with this provider's API.
     *
     * @return bool
     */
    public function hostsServers(): bool
    {
        return in_array($this, [self::DigitalOcean, self::Hetzner, self::Vultr, self::Linode, self::Lightsail, self::Ec2, self::GoogleCompute, self::Azure, self::Ovh, self::Scaleway, self::UpCloud], true);
    }

    /**
     * Determine whether the provider hosts Git repositories we deploy from.
     *
     * @return bool
     */
    public function isSourceControl(): bool
    {
        return in_array($this, [self::GitHub, self::GitLab, self::Bitbucket], true);
    }

    /**
     * Get the Git host repositories are cloned from, for source-control providers.
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
     * Get the username that goes with the token for Git over HTTPS.
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
     * Get the providers that can host servers, for the server creation form.
     *
     * @return list<self>
     */
    public static function serverHosts(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->hostsServers()));
    }
}
