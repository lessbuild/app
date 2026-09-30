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
            'same' => [
                'Servers are created in your own cloud account (DigitalOcean, Hetzner Cloud, Vultr, Linode or AWS Lightsail with us), so you keep control of them and their bills.',
                'Websites get domains, certificates and Git deploys from GitHub, GitLab or Bitbucket.',
                'Cron jobs, background processes (queues, Horizon, Reverb), firewall rules and web server settings.',
            ],
            'different' => [
                'Monitoring, error tracking, status pages and privacy-friendly Analytics are part of the same account, so a deploy shows up beside the errors and traffic it affected.',
                'Safe release strategies (blue-green, canary, rolling), approvals, deploy windows, previews for every pull request and one-click rollback are built in.',
                'Meilisearch, Typesense and Redis in a click, Cloudflare CDN and firewall rules per domain, and storage buckets for your apps.',
                'One bill across all four services, each with a free tier.',
            ],
            'choose_them' => 'You only need server management and deploys, and already have monitoring and analytics you like.',
        ],
        'laravel-cloud' => [
            'name' => 'Laravel Cloud',
            'summary' => 'Laravel Cloud is a fully managed platform for Laravel applications: it runs them on its own infrastructure, with managed databases, caches and storage.',
            'same' => [
                'Push to deploy, preview environments and autoscaling.',
                'Databases, caches and storage buckets beside your application, and usage-based billing past a plan’s allowance.',
            ],
            'different' => [
                'Your apps run on servers in your own cloud account, so you choose the provider and region, see the cloud bill, and can SSH in when you need to.',
                'Monitoring, error tracking, status pages and Analytics are in the same account, and traces show the deploy that served them.',
                'Not only Laravel: any app that runs on a Linux server.',
            ],
            'choose_them' => 'You want someone else to run the servers entirely and are happy for your apps to live on their infrastructure.',
        ],
        'ploi' => [
            'name' => 'Ploi',
            'summary' => 'Ploi is a server management panel: it sets up servers in your cloud accounts and deploys sites to them.',
            'same' => [
                'Servers stay in your own cloud account.',
                'Sites get domains, certificates, databases, cron jobs, daemons and Git deploys.',
            ],
            'different' => [
                'Uptime checks, errors, traces, incidents, on-call and status pages are in the same place as your deploys.',
                'Previews per pull request (optionally with a copy of a database), approvals and automatic rollback when a release fails its health checks.',
                'Cookieless Analytics shows each release beside the traffic.',
            ],
            'choose_them' => 'You want a server panel on its own and are happy to bring separate tools for monitoring and analytics.',
        ],
        'vercel' => [
            'name' => 'Vercel',
            'summary' => 'Vercel hosts frontend and full-stack apps (Next.js especially) on its own managed, serverless infrastructure.',
            'same' => [
                'Deploys from Git, with a preview for every pull request.',
                'Built-in analytics and monitoring alongside deploys, and a CDN and firewall in front of your sites.',
            ],
            'different' => [
                'Your apps run on servers in your own cloud account, so long-running workers, queues, cron jobs and databases sit beside them, and the cloud bill is yours to see.',
                'Built for server-rendered apps in PHP, Node, Python and more, not only serverless functions.',
                'Server-level monitoring, backups and SSH access when you need them.',
            ],
            'choose_them' => 'You’re building a frontend-heavy app and want someone else to run the infrastructure entirely.',
        ],
        'plausible' => [
            'name' => 'Plausible Analytics',
            'summary' => 'Plausible is lightweight, privacy-friendly web analytics without cookies.',
            'same' => [
                'No cookies and no personal data, with one small script you can serve from your own domain.',
                'Pages, sources, channels, campaigns, places, devices, goals, funnels and custom properties, with custom date ranges, comparisons and segments.',
                'Email reports, traffic spike alerts, shared and embedded dashboards, and an import from Google Analytics.',
            ],
            'different' => [
                'Your releases are marked on the chart, and the same account deploys and monitors the site.',
                'Path exploration, automatic insights, items sold, opt-in retention, Slack reports and nightly raw exports ready for BigQuery.',
                'One bill with Deploy, Infrastructure and Monitoring, with a free tier for each.',
            ],
            'choose_them' => 'You only need analytics, or want an analytics product that’s independent of your hosting.',
        ],
        'google-analytics' => [
            'name' => 'Google Analytics',
            'summary' => 'Google Analytics is Google’s free, full-featured web and app analytics, with deep reporting and Google Ads integration.',
            'same' => [
                'Channels, campaigns, goals, funnels, path exploration, retention cohorts and e-commerce item reports.',
                'Raw data you can analyse in BigQuery.',
            ],
            'different' => [
                'No cookies and no personal data by default, and far simpler reports. Check with your advisers, but many sites don’t need a consent banner for it.',
                'Your releases sit beside the traffic, in the same account that deploys and monitors the site.',
                'Bring your history with you: import a GA4 property’s daily data in a few clicks.',
            ],
            'choose_them' => 'You rely on Google Ads, audiences and cross-device reporting, and want analytics tied into Google’s marketing tools.',
        ],
    ],
];
