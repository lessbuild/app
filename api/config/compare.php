<?php

declare(strict_types=1);

// Comparison pages. Only claims we are confident are true of each product go here: what it is for and when you would pick
// it, what each includes where we know it, and how to move. No quotes or prices for other products. Checked against
// their public sites in September 2026. Fields: what (its category), overlaps (our services it competes with), pitch
// and stance (the headings), glance (label, us, them), stronger (where they are the better pick), reasons, included
// (feature, us, them; true, false or a note), migrate (three steps) and guide (the help guide for moving).
return [
    'checked' => 'September 2026',
    'competitors' => [
        'laravel-forge' => [
            'name' => 'Laravel Forge',
            'summary' => 'Forge provisions and manages servers in your cloud accounts for PHP and Laravel applications, and deploys them from Git.',
            'same' => [
                'Servers are created in your own cloud account (DigitalOcean, Hetzner Cloud, Vultr, Linode, AWS Lightsail or EC2, Google Compute Engine, Microsoft Azure, OVHcloud, Scaleway or UpCloud with us), so you keep control of them and their bills.',
                'Websites get domains, certificates and Git deploys from GitHub, GitLab or Bitbucket.',
                'Cron jobs, background processes (queues, Horizon, Reverb), firewall rules and web server settings.',
            ],
            'different' => [
                'Monitoring, error tracking, status pages and privacy-friendly Analytics are part of the same account, so a deploy shows up beside the errors and traffic it affected.',
                'Safe release strategies (blue-green, canary, rolling), approvals, deploy windows, previews for every pull request and one-click rollback are built in.',
                'Meilisearch, Typesense and Redis in a click, Cloudflare CDN and firewall rules per domain, and storage buckets for your apps.',
                'Security scanning built in: vulnerable packages, leaked secrets, server hardening scores, automatic attack blocking and compliance evidence.',
                'One bill across every service, each with a free tier.',
                'Moving is guided: connect a Forge API token and each site is recreated with its environment file, cron jobs and daemons.',
            ],
            'choose_them' => 'You only need server management and deploys, and already have monitoring and analytics you like.',
            'what' => 'Server management',
            'overlaps' => [
                'deploy',
                'infrastructure',
            ],
            'pitch' => 'all-in-one',
            'stance' => [
                'a focused server manager for PHP',
                'servers, deploys, monitoring, security and analytics together',
            ],
            'glance' => [
                [
                    'Focus',
                    'Deploy, run, monitor, secure and analyse',
                    'Provision servers and deploy PHP apps',
                ],
                [
                    'Where your apps run',
                    'Servers in your own cloud account',
                    'Servers in your own cloud account',
                ],
                [
                    'How you pay',
                    'Per service, with a free tier on each',
                    'A subscription for the account',
                ],
                [
                    'Errors and traces',
                    'Built in (Monitoring)',
                    'A separate product (Laravel Nightwatch)',
                ],
                [
                    'Visitor analytics',
                    'Built in (Analytics)',
                    'Bring your own',
                ],
            ],
            'stronger' => [
                'Built by the Laravel team, so new Laravel features tend to arrive there first.',
                'A narrower product, with fewer settings to learn if servers and deploys are all you need.',
            ],
            'reasons' => [
                [
                    'title' => 'A deploy and its errors in one place',
                    'text' => 'When a release goes wrong, Monitoring shows the deploy that was live beside the errors and slow requests, and you can roll back from the same screen.',
                ],
                [
                    'title' => 'Safer releases',
                    'text' => 'Blue-green, canary and rolling strategies, approvals, deploy windows, a preview for every pull request and automatic rollback when health checks fail.',
                ],
                [
                    'title' => 'Fewer tools to pay for',
                    'text' => 'Uptime checks, error tracking, status pages, security scanning and privacy-friendly analytics come with the same account and bill, each with a free tier.',
                ],
            ],
            'included' => [
                [
                    'group' => 'Servers and sites',
                    'rows' => [
                        [
                            'Servers in your own cloud account',
                            true,
                            true,
                        ],
                        [
                            'Domains and automatic TLS',
                            true,
                            true,
                        ],
                        [
                            'Cron jobs, daemons and firewall rules',
                            true,
                            true,
                        ],
                        [
                            'Recipes for new servers',
                            true,
                            true,
                        ],
                        [
                            'Database backups',
                            true,
                            true,
                        ],
                    ],
                ],
                [
                    'group' => 'Releases',
                    'rows' => [
                        [
                            'Deploys from GitHub, GitLab and Bitbucket',
                            true,
                            true,
                        ],
                        [
                            'Zero-downtime deploys',
                            true,
                            true,
                        ],
                        [
                            'Blue-green and canary releases',
                            true,
                            false,
                        ],
                        [
                            'Approvals and deploy windows',
                            true,
                            false,
                        ],
                    ],
                ],
                [
                    'group' => 'After release',
                    'rows' => [
                        [
                            'Error tracking and traces',
                            true,
                            'Laravel Nightwatch',
                        ],
                        [
                            'Uptime checks, incidents and status pages',
                            true,
                            false,
                        ],
                        [
                            'Privacy-friendly analytics',
                            true,
                            false,
                        ],
                        [
                            'Package, secret and server security scanning',
                            true,
                            false,
                        ],
                    ],
                ],
            ],
            'migrate' => [
                'Paste a Forge API token under Move from Forge or Ploi',
                'Move each site to one of your servers here: its environment file, cron jobs and daemons come with it',
                'Connect the repository, deploy, copy the database and point DNS at the new server',
            ],
            'guide' => 'move-from-forge-or-ploi',
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
                'Security scanning, a deploy gate for vulnerable packages, server hardening and compliance evidence, in the same account.',
            ],
            'choose_them' => 'You want someone else to run the servers entirely and are happy for your apps to live on their infrastructure.',
            'what' => 'Managed Laravel hosting',
            'overlaps' => [
                'deploy',
                'infrastructure',
                'monitoring',
            ],
            'pitch' => 'own-your-servers',
            'stance' => [
                'fully managed hosting for Laravel',
                'the convenience of a platform on servers you own',
            ],
            'glance' => [
                [
                    'Focus',
                    'Deploy to servers you own, then monitor, secure and analyse',
                    'Run Laravel apps on managed infrastructure',
                ],
                [
                    'Where your apps run',
                    'Servers in your own cloud account',
                    'Laravel Cloud’s infrastructure',
                ],
                [
                    'How you pay',
                    'Per service, plus your provider’s server price',
                    'A plan plus usage',
                ],
                [
                    'Server access',
                    'SSH, commands and a browser terminal',
                    'Managed for you',
                ],
                [
                    'Stacks',
                    'Any app that runs on Linux',
                    'Laravel apps',
                ],
            ],
            'stronger' => [
                'No servers to think about at all, including patching and capacity.',
                'Managed databases, caches and storage that scale with usage.',
            ],
            'reasons' => [
                [
                    'title' => 'Your servers, your bill',
                    'text' => 'Apps run on servers in your own cloud account, at your provider’s price, in the region you choose. Leaving means your servers simply keep running.',
                ],
                [
                    'title' => 'A way in when things break',
                    'text' => 'SSH, one-off commands and a browser terminal are there when you need to see what a server is doing.',
                ],
                [
                    'title' => 'More than hosting',
                    'text' => 'Security scanning, privacy-friendly analytics and site audits sit beside deploys and monitoring, on the same bill.',
                ],
            ],
            'included' => [
                [
                    'group' => 'Deploying',
                    'rows' => [
                        [
                            'Push to deploy',
                            true,
                            true,
                        ],
                        [
                            'Preview environments',
                            true,
                            true,
                        ],
                        [
                            'Autoscaling',
                            true,
                            true,
                        ],
                        [
                            'Servers in your own cloud account',
                            true,
                            false,
                        ],
                        [
                            'Choose any provider and region',
                            true,
                            false,
                        ],
                    ],
                ],
                [
                    'group' => 'Control',
                    'rows' => [
                        [
                            'SSH and a browser terminal',
                            true,
                            false,
                        ],
                        [
                            'Any stack, not only Laravel',
                            true,
                            false,
                        ],
                    ],
                ],
                [
                    'group' => 'Beyond hosting',
                    'rows' => [
                        [
                            'Privacy-friendly analytics',
                            true,
                            false,
                        ],
                        [
                            'Package, secret and server security scanning',
                            true,
                            false,
                        ],
                        [
                            'Public status pages',
                            true,
                            false,
                        ],
                    ],
                ],
            ],
            'migrate' => [
                'Connect your cloud provider and create a server',
                'Connect the repository, add the environment’s variables and deploy a preview',
                'Copy the database across and point DNS at the new server when the preview looks right',
            ],
            'guide' => 'deploy-from-git',
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
                'Security checks your packages, code, servers and domains, blocks attackers, and stops deploys with known critical vulnerabilities.',
                'Moving is guided: connect a Ploi API token and each site is recreated with its environment file, cron jobs and daemons.',
            ],
            'choose_them' => 'You want a server panel on its own and are happy to bring separate tools for monitoring and analytics.',
            'what' => 'Server management',
            'overlaps' => [
                'deploy',
                'infrastructure',
            ],
            'pitch' => 'all-in-one',
            'stance' => [
                'a capable server panel',
                'servers, deploys, monitoring, security and analytics together',
            ],
            'glance' => [
                [
                    'Focus',
                    'Deploy, run, monitor, secure and analyse',
                    'Manage servers and deploy sites',
                ],
                [
                    'Where your apps run',
                    'Servers in your own cloud account',
                    'Servers in your own cloud account',
                ],
                [
                    'How you pay',
                    'Per service, with a free tier on each',
                    'A subscription for the account',
                ],
                [
                    'Errors and traces',
                    'Built in (Monitoring)',
                    'Bring your own',
                ],
                [
                    'Visitor analytics',
                    'Built in (Analytics)',
                    'Bring your own',
                ],
            ],
            'stronger' => [
                'A long-established panel with a wide range of server and site settings.',
                'If you only want a server panel, there’s less to learn.',
            ],
            'reasons' => [
                [
                    'title' => 'Deploys you can see the effect of',
                    'text' => 'Each release is marked in Monitoring and Analytics, so errors, slow requests and traffic changes point at the deploy that caused them.',
                ],
                [
                    'title' => 'Previews and safe releases',
                    'text' => 'A preview for every pull request (optionally with a copy of the database), approvals and automatic rollback when a release fails its health checks.',
                ],
                [
                    'title' => 'Security built in',
                    'text' => 'Vulnerable packages, leaked secrets, server hardening and attack blocking, with deploys stopped when a critical vulnerability ships.',
                ],
            ],
            'included' => [
                [
                    'group' => 'Servers and sites',
                    'rows' => [
                        [
                            'Servers in your own cloud account',
                            true,
                            true,
                        ],
                        [
                            'Domains and automatic TLS',
                            true,
                            true,
                        ],
                        [
                            'Databases, cron jobs and daemons',
                            true,
                            true,
                        ],
                        [
                            'Backups',
                            true,
                            true,
                        ],
                    ],
                ],
                [
                    'group' => 'Releases',
                    'rows' => [
                        [
                            'Git deploys',
                            true,
                            true,
                        ],
                        [
                            'Zero-downtime deploys',
                            true,
                            true,
                        ],
                        [
                            'Preview per pull request',
                            true,
                            false,
                        ],
                        [
                            'Approvals and automatic rollback',
                            true,
                            false,
                        ],
                    ],
                ],
                [
                    'group' => 'After release',
                    'rows' => [
                        [
                            'Error tracking and traces',
                            true,
                            false,
                        ],
                        [
                            'Privacy-friendly analytics',
                            true,
                            false,
                        ],
                        [
                            'Package and secret scanning',
                            true,
                            false,
                        ],
                    ],
                ],
            ],
            'migrate' => [
                'Paste a Ploi API token under Move from Forge or Ploi',
                'Move each site to one of your servers here: its environment file, cron jobs and daemons come with it',
                'Connect the repository, deploy, copy the database and point DNS at the new server',
            ],
            'guide' => 'move-from-forge-or-ploi',
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
            'what' => 'Frontend cloud',
            'overlaps' => [
                'deploy',
                'monitoring',
                'analytics',
            ],
            'pitch' => 'server-friendly',
            'stance' => [
                'a managed frontend cloud',
                'deploys to servers you own, with everything around them',
            ],
            'glance' => [
                [
                    'Focus',
                    'Server-rendered apps, workers and databases on your servers',
                    'Frontend and serverless apps',
                ],
                [
                    'Where your apps run',
                    'Servers in your own cloud account',
                    'Vercel’s infrastructure',
                ],
                [
                    'How you pay',
                    'Per service, plus your provider’s server price',
                    'A plan plus usage',
                ],
                [
                    'Long-running processes',
                    'Queue workers, schedulers and daemons',
                    'Functions with time limits',
                ],
                [
                    'Server access',
                    'SSH, commands and a browser terminal',
                    'Managed for you',
                ],
            ],
            'stronger' => [
                'A global edge network and first-class Next.js support.',
                'Nothing to run yourself, from builds to scaling.',
            ],
            'reasons' => [
                [
                    'title' => 'Workers, queues and databases beside your app',
                    'text' => 'Your app runs on real servers, so long-running workers, cron jobs and a database sit right next to it.',
                ],
                [
                    'title' => 'A bill you can predict',
                    'text' => 'Servers cost your provider’s price, and each service has its own plan with a free tier.',
                ],
                [
                    'title' => 'Server-level visibility',
                    'text' => 'Server metrics, backups, SSH and a browser terminal are there when you need them, alongside monitoring and analytics.',
                ],
            ],
            'included' => [
                [
                    'group' => 'Deploying',
                    'rows' => [
                        [
                            'Deploys from Git',
                            true,
                            true,
                        ],
                        [
                            'Preview per pull request',
                            true,
                            true,
                        ],
                        [
                            'Servers in your own cloud account',
                            true,
                            false,
                        ],
                        [
                            'Queue workers and daemons',
                            true,
                            false,
                        ],
                    ],
                ],
                [
                    'group' => 'Around your app',
                    'rows' => [
                        [
                            'Analytics',
                            true,
                            true,
                        ],
                        [
                            'Firewall and CDN',
                            true,
                            true,
                        ],
                        [
                            'Uptime checks, incidents and status pages',
                            true,
                            false,
                        ],
                        [
                            'Package, secret and server security scanning',
                            true,
                            false,
                        ],
                    ],
                ],
            ],
            'migrate' => [
                'Connect your cloud provider and create an app server',
                'Connect the repository, add the environment’s variables and deploy a preview',
                'Point DNS at the new server when the preview looks right',
            ],
            'guide' => 'deploy-from-git',
        ],
        'plausible' => [
            'name' => 'Plausible Analytics',
            'summary' => 'Plausible is lightweight, privacy-friendly web analytics without cookies.',
            'same' => [
                'No cookies and no personal data, with one small script you can serve from your own domain.',
                'Pages, sources, channels, campaigns, places, devices, goals, funnels and custom properties, with custom date ranges, comparisons and segments.',
                'Email reports, scheduled CSV exports, traffic spike and unusual traffic alerts, shared and embedded dashboards, and an import from Google Analytics.',
            ],
            'different' => [
                'Your releases are marked on the chart, and the same account deploys and monitors the site.',
                'Path exploration, automatic insights, attribution, ad spend with return on ad spend, click maps, form analytics, A/B tests, items sold, opt-in retention, Slack reports, a Looker Studio connector and nightly raw exports ready for BigQuery.',
                'One bill with Deploy, Infrastructure and Monitoring, with a free tier for each.',
            ],
            'choose_them' => 'You only need analytics, or want an analytics product that’s independent of your hosting.',
            'what' => 'Website analytics',
            'overlaps' => [
                'analytics',
            ],
            'pitch' => 'release-aware',
            'stance' => [
                'focused, independent analytics',
                'analytics beside the deploys that change your traffic',
            ],
            'glance' => [
                [
                    'Focus',
                    'Traffic beside releases, incidents and the rest of your stack',
                    'Website analytics',
                ],
                [
                    'Cookies',
                    'None',
                    'None',
                ],
                [
                    'How you pay',
                    'Free tier, then by pageviews',
                    'By pageviews',
                ],
                [
                    'Deeper analysis',
                    'Paths, retention, attribution, A/B tests and click maps',
                    'Goals, funnels and custom properties',
                ],
                [
                    'Also in the account',
                    'Deploy, Infrastructure, Monitoring, Security and Audit',
                    'Analytics only',
                ],
            ],
            'stronger' => [
                'Analytics is all it does, from an independent company.',
                'Open source, with a community edition you can host yourself.',
            ],
            'reasons' => [
                [
                    'title' => 'See what a release did to traffic',
                    'text' => 'Releases from Deploy are marked on the chart, so a drop in sign-ups points at the change that caused it.',
                ],
                [
                    'title' => 'Go deeper when you need to',
                    'text' => 'Path exploration, retention, attribution, ad spend with return on ad spend, click maps, form analytics and A/B tests.',
                ],
                [
                    'title' => 'One account for the whole stack',
                    'text' => 'The same team, roles and bill as your deploys and monitoring, with a free tier for each service.',
                ],
            ],
            'included' => [
                [
                    'group' => 'Reporting',
                    'rows' => [
                        [
                            'Cookieless tracking',
                            true,
                            true,
                        ],
                        [
                            'Goals, funnels and custom properties',
                            true,
                            true,
                        ],
                        [
                            'Import from Google Analytics',
                            true,
                            true,
                        ],
                        [
                            'Shared and embedded dashboards',
                            true,
                            true,
                        ],
                    ],
                ],
                [
                    'group' => 'Going deeper',
                    'rows' => [
                        [
                            'Path exploration and retention',
                            true,
                            false,
                        ],
                        [
                            'A/B tests',
                            true,
                            false,
                        ],
                        [
                            'Ad spend and return on ad spend',
                            true,
                            false,
                        ],
                        [
                            'Releases marked on the chart',
                            true,
                            false,
                        ],
                    ],
                ],
            ],
            'migrate' => [
                'Add your site under Analytics → Sites',
                'Swap the Plausible script for the one-line BuildPusher script',
                'Recreate your goals and funnels; visits appear within a minute',
            ],
            'guide' => 'add-analytics',
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
            'what' => 'Website analytics',
            'overlaps' => [
                'analytics',
            ],
            'pitch' => 'simpler, cookieless',
            'stance' => [
                'deep, cookie-based marketing analytics',
                'simple, cookieless analytics beside your releases',
            ],
            'glance' => [
                [
                    'Focus',
                    'Clear traffic reports beside your releases',
                    'Detailed marketing and app analytics',
                ],
                [
                    'Cookies',
                    'None',
                    'Uses cookies',
                ],
                [
                    'How you pay',
                    'Free tier, then by pageviews',
                    'Free (with a paid enterprise edition)',
                ],
                [
                    'Reports',
                    'One page, plus Explore when you need it',
                    'Many reports and custom explorations',
                ],
                [
                    'Ads',
                    'Import or connect ad spend for return on ad spend',
                    'Deep Google Ads integration',
                ],
            ],
            'stronger' => [
                'Free at large volumes, with deep Google Ads and audience integration.',
                'Cross-device and app analytics in the same property.',
            ],
            'reasons' => [
                [
                    'title' => 'No cookie banner for analytics',
                    'text' => 'No cookies and no personal data by default. Check with your advisers, but many sites don’t need consent for it.',
                ],
                [
                    'title' => 'Reports people actually read',
                    'text' => 'One clear overview, with paths, retention, attribution and A/B tests in Explore when you need more.',
                ],
                [
                    'title' => 'Your history comes with you',
                    'text' => 'Import a GA4 property’s daily data in a few clicks, then keep both running while you compare.',
                ],
            ],
            'included' => [
                [
                    'group' => 'Reporting',
                    'rows' => [
                        [
                            'Channels, campaigns and goals',
                            true,
                            true,
                        ],
                        [
                            'Funnels and path exploration',
                            true,
                            true,
                        ],
                        [
                            'Raw data in BigQuery',
                            true,
                            true,
                        ],
                        [
                            'Cookieless by default',
                            true,
                            false,
                        ],
                    ],
                ],
                [
                    'group' => 'Around your site',
                    'rows' => [
                        [
                            'Releases marked on the chart',
                            true,
                            false,
                        ],
                        [
                            'Uptime and error monitoring',
                            true,
                            false,
                        ],
                        [
                            'Google Ads audiences',
                            false,
                            true,
                        ],
                    ],
                ],
            ],
            'migrate' => [
                'Add your site under Analytics → Sites',
                'Choose Connect Google Analytics to import your GA4 history',
                'Add the one-line script and recreate your goals',
            ],
            'guide' => 'analytics-data',
        ],
        'laravel-nightwatch' => [
            'name' => 'Laravel Nightwatch',
            'summary' => 'Laravel Nightwatch is the Laravel team’s own monitoring for Laravel applications, installed as a package that reports what the app does.',
            'same' => [
                'A composer package that records requests, exceptions, slow queries and queue jobs, linked together.',
                'Exceptions grouped into issues you can assign and resolve.',
            ],
            'different' => [
                'Uptime, DNS, TLS and heartbeat checks, incidents with on-call and escalations, and public status pages in the same place.',
                'Not only Laravel: any stack can send events or OpenTelemetry, and browser errors are caught too.',
                'Deploys, servers, security scanning and Analytics are in the same account, and every trace shows the deploy that served it.',
            ],
            'choose_them' => 'You want monitoring built by the Laravel team, with the deepest view of Laravel’s own internals.',
            'what' => 'Application monitoring',
            'overlaps' => [
                'monitoring',
            ],
            'pitch' => 'full-stack',
            'stance' => [
                'deep monitoring for Laravel apps',
                'monitoring tied to your deploys, uptime and status pages',
            ],
            'glance' => [
                [
                    'Focus',
                    'Uptime, errors, traces, incidents and status pages',
                    'What a Laravel app does at runtime',
                ],
                [
                    'Stacks',
                    'Laravel, plus any stack over OpenTelemetry',
                    'Laravel',
                ],
                [
                    'How you pay',
                    'Free tier, then by events',
                    'By events',
                ],
                [
                    'Uptime and status pages',
                    'Built in',
                    'Not the focus',
                ],
                [
                    'Deploys',
                    'Every trace shows the deploy that served it',
                    'Bring your own',
                ],
            ],
            'stronger' => [
                'Built by the Laravel team, with the deepest view of Laravel’s own internals.',
                'A focused tool if application monitoring is all you need.',
            ],
            'reasons' => [
                [
                    'title' => 'From an error to the deploy that caused it',
                    'text' => 'Every issue and trace shows the release that was live, and you can roll back from the same account.',
                ],
                [
                    'title' => 'Outside-in checks too',
                    'text' => 'HTTP, DNS, TLS, TCP, heartbeat and queue checks, incidents with on-call and escalations, and public status pages.',
                ],
                [
                    'title' => 'Any stack',
                    'text' => 'Laravel through the buildpusher/laravel package, anything else through OpenTelemetry, and browser errors from your pages.',
                ],
            ],
            'included' => [
                [
                    'group' => 'Application',
                    'rows' => [
                        [
                            'Requests, queries, jobs and exceptions',
                            true,
                            true,
                        ],
                        [
                            'Exceptions grouped into issues',
                            true,
                            true,
                        ],
                        [
                            'OpenTelemetry from any stack',
                            true,
                            false,
                        ],
                        [
                            'Browser errors',
                            true,
                            false,
                        ],
                    ],
                ],
                [
                    'group' => 'Around your app',
                    'rows' => [
                        [
                            'Uptime, DNS, TLS and heartbeat checks',
                            true,
                            false,
                        ],
                        [
                            'Incidents, on-call and status pages',
                            true,
                            false,
                        ],
                        [
                            'Deploys and rollback in the same account',
                            true,
                            false,
                        ],
                    ],
                ],
            ],
            'migrate' => [
                'composer require buildpusher/laravel and set BUILDPUSHER_TOKEN',
                'Add uptime checks and choose where alerts go',
                'Mark releases with php artisan buildpusher:deploy and compare the two for a while',
            ],
            'guide' => 'send-telemetry',
        ],
    ],
];
