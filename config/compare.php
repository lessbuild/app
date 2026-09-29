<?php

declare(strict_types=1);

// Comparison pages. Only claims we're confident are true of each product go here: what it's for and when you'd pick
// it, not a feature grid of someone else's product. Checked against their public sites in September 2026.
return [
    'checked' => 'September 2026',
    'competitors' => [
        'laravel-forge' => [
            'name' => 'Laravel Forge',
            'summary' => 'Forge provisions and manages servers in your cloud accounts for PHP and Laravel applications, and deploys them from Git.',
            'same' => ['Servers are created in your own DigitalOcean, Hetzner Cloud or Vultr account, so you keep control of them and their bills.', 'Websites get domains and certificates, and deploy from GitHub, GitLab or Bitbucket.'],
            'different' => ['Monitoring, error tracking, status pages and privacy-friendly Analytics are part of the same account, so a deploy shows up beside the errors and traffic it affected.', 'Safe release strategies (blue-green, canary, rolling), approvals, deploy windows, previews for every pull request and one-click rollback are built in.', 'One bill across all four services, each with a free tier.'],
            'choose_them' => 'You only need server management and deploys, and already have monitoring and analytics you like.',
        ],
        'ploi' => [
            'name' => 'Ploi',
            'summary' => 'Ploi is a server management panel: it sets up servers in your cloud accounts and deploys sites to them.',
            'same' => ['Servers stay in your own cloud account.', 'Sites get domains, certificates, databases and Git deploys.'],
            'different' => ['Uptime checks, errors, traces, incidents and status pages are in the same place as your deploys.', 'Previews per pull request, approvals and automatic rollback when a release fails its health checks.', 'Cookieless Analytics shows each release beside the traffic.'],
            'choose_them' => 'You want a server panel on its own and are happy to bring separate tools for monitoring and analytics.',
        ],
        'vercel' => [
            'name' => 'Vercel',
            'summary' => 'Vercel hosts frontend and full-stack apps (Next.js especially) on its own managed, serverless infrastructure.',
            'same' => ['Deploys from Git, with a preview for every pull request.', 'Built-in analytics and monitoring alongside deploys.'],
            'different' => ['Your apps run on servers in your own cloud account, so long-running workers, queues, cron jobs and databases sit beside them, and the cloud bill is yours to see.', 'Built for server-rendered apps in PHP, Node, Python and more, not only serverless functions.', 'Server-level monitoring, backups and SSH access when you need them.'],
            'choose_them' => 'You’re building a frontend-heavy app and want someone else to run the infrastructure entirely.',
        ],
        'plausible' => [
            'name' => 'Plausible Analytics',
            'summary' => 'Plausible is lightweight, privacy-friendly web analytics without cookies.',
            'same' => ['No cookies and no personal data, with one small script.', 'Pages, sources, campaigns, devices and goals in a simple report.'],
            'different' => ['Your releases are listed beside the traffic, and the same account deploys and monitors the site.', 'One bill with Deploy, Infrastructure and Monitoring, with a free tier for each.'],
            'choose_them' => 'You only need analytics, or want an analytics product that’s independent of your hosting.',
        ],
    ],
];
