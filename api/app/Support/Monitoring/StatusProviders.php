<?php

declare(strict_types=1);

namespace App\Support\Monitoring;

/** Common services whose status pages publish a Statuspage-style summary (/api/v2/summary.json). */
final class StatusProviders
{
    /**
     * Each provider's name and status page address.
     *
     * @var array<string, array{name: string, url: string}>
     */
    public const LIST = [
        'github' => ['name' => 'GitHub', 'url' => 'https://www.githubstatus.com'],
        'bitbucket' => ['name' => 'Bitbucket', 'url' => 'https://bitbucket.status.atlassian.com'],
        'cloudflare' => ['name' => 'Cloudflare', 'url' => 'https://www.cloudflarestatus.com'],
        'digitalocean' => ['name' => 'DigitalOcean', 'url' => 'https://status.digitalocean.com'],
        'linode' => ['name' => 'Akamai (Linode)', 'url' => 'https://status.linode.com'],
        'vercel' => ['name' => 'Vercel', 'url' => 'https://www.vercel-status.com'],
        'npm' => ['name' => 'npm', 'url' => 'https://status.npmjs.org'],
        'twilio' => ['name' => 'Twilio', 'url' => 'https://status.twilio.com'],
        'sendgrid' => ['name' => 'SendGrid', 'url' => 'https://status.sendgrid.com'],
        'mailgun' => ['name' => 'Mailgun', 'url' => 'https://status.mailgun.com'],
        'discord' => ['name' => 'Discord', 'url' => 'https://discordstatus.com'],
        'dropbox' => ['name' => 'Dropbox', 'url' => 'https://status.dropbox.com'],
    ];
}
