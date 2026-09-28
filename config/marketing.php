<?php

declare(strict_types=1);

// The public site's words: the home page and one page per service. Adapted from the old apps' marketing configuration,
// keeping only what the platform does today.
return [
    'headline' => 'Ship, run and understand your apps from one place.',
    'summary' => 'Deploy from Git to servers in your own cloud accounts, watch every environment, and see how people use what you ship, with one account, one bill and one set of permissions.',

    'hero' => [
        'badge' => 'One platform for your applications',
        'headline' => 'Ship with confidence.',
        'accent' => 'Know what happens next.',
        'points' => ['Your own cloud accounts', 'One account and one bill', 'Start free'],
    ],

    'workflow' => [
        ['01', 'Ship', 'Connect a repository, provision what you need, and release an exact revision.', 'cloud-upload'],
        ['02', 'Observe', 'Follow health, errors, traces and incidents as the system changes.', 'pulse'],
        ['03', 'Understand', 'See how people use what you shipped, with each release beside the traffic.', 'chart'],
        ['04', 'Recover', 'Keep the history and controls to restore a known good release in seconds.', 'refresh'],
    ],

    'integrations' => [
        ['Deploy → Monitoring and Analytics', 'Every live deploy becomes a release marker: issues and incidents point at the release that caused them, and Analytics reports list it beside the traffic.'],
        ['Infrastructure → Deploy', 'Repositories deploy to the websites and servers you manage here, with backups, domains and load balancers beside them.'],
        ['Monitoring → everyone', 'Alerts, incidents and status pages are shared by every service, with one set of destinations and escalations.'],
        ['One account', 'Members, roles, per-service access, API tokens, audit history and billing are set once for all four services.'],
    ],

    'services' => [
        'deploy' => [
            'card' => ['badge' => 'Deploy', 'tone' => 'primary', 'icon' => 'cloud-upload', 'chips' => ['Git-connected releases', 'Previews per pull request', 'Rollback in seconds'], 'preview' => ['title' => 'Production release', 'subtitle' => 'storefront · main · a71c8ef', 'status' => 'Live', 'status_tone' => 'success', 'metrics' => [['Fetch revision', 'Complete'], ['Activate release', 'Complete'], ['Verify health', 'Passed']]]],
            'eyebrow' => 'Build and ship',
            'headline' => 'Deploy with clarity. Recover with confidence.',
            'summary' => 'Release from GitHub, GitLab or Bitbucket to servers you own, with approvals, safe strategies, previews and a rollback that’s always one click away.',
            'groups' => [
                ['Release', [
                    ['Release per build', 'Each deploy is its own release directory; the previous ones stay for instant rollbacks.'],
                    ['Safe strategies', 'Blue-green, canary and rolling deploys, with health observation and automatic rollback.'],
                    ['Approvals and controls', 'Approval by someone else, deploy locks with a reason, and weekly deploy windows.'],
                    ['Promotion', 'Promote a tested build from preview to development, staging and production, keeping its lineage.'],
                ]],
                ['Previews', [
                    ['A stack per pull request', 'Each pull request gets its own website, environment and deploys, removed when it closes.'],
                    ['Secrets stay safe', 'Previews never inherit secrets; you approve chosen ones for an exact revision.'],
                    ['GitHub checks', 'GitHub App repositories get a check run and a comment that follows the preview.'],
                ]],
                ['Automate', [
                    ['Scheduled deploys and tasks', 'Cron schedules in your time zone, task history with output, and alerts when a task starts failing.'],
                    ['Workers and hibernation', 'Queue workers and schedulers as services, scaling schedules, and idle environments that sleep until the next request.'],
                    ['Configuration as code', 'Plan, review and apply environment configuration documents, from the dashboard or the API.'],
                    ['Deployer API v1', 'Deploy, roll back, promote, scale and manage variables from scripts, with scoped API tokens.'],
                ]],
            ],
            'questions' => [
                ['Where does my application run?', 'On servers in the cloud accounts you connect (DigitalOcean, Hetzner Cloud or Vultr), or servers you import.'],
                ['Can I go back to an earlier release?', 'Yes. Retained releases switch back in seconds, and failed live deploys can roll back on their own.'],
                ['Do my old scripts keep working?', 'Yes. The Deployer API v1 keeps its paths, fields and status codes, including old numeric IDs.'],
            ],
        ],
        'infrastructure' => [
            'card' => ['badge' => 'Provision', 'tone' => 'info', 'icon' => 'server', 'chips' => ['DigitalOcean, Hetzner, Vultr', 'Domains and TLS', 'Verified backups'], 'preview' => ['title' => 'web-1 · fra1', 'subtitle' => 'Ubuntu 24.04 · 2 vCPU · 4 GB', 'status' => 'Active', 'status_tone' => 'success', 'metrics' => [['CPU', '18%'], ['Websites', '3'], ['Last backup', '2h ago']]]],
            'eyebrow' => 'Servers you own',
            'headline' => 'Provision without the guesswork.',
            'summary' => 'Create and import servers, host websites with domains and TLS, back them up, and keep an eye on what it all costs.',
            'groups' => [
                ['Servers', [
                    ['Cloud provisioning', 'Create servers at DigitalOcean, Hetzner Cloud or Vultr, or import one over SSH, and follow each setup stage.'],
                    ['Commands and terminal', 'Run commands with history, open a browser terminal, and collect logs, metrics and diagnostics.'],
                    ['Recipes', 'Scripts that run on new servers, from your library or a gallery other teams share.'],
                ]],
                ['Websites', [
                    ['Domains and TLS', 'Primary domains, aliases and redirects with automatic certificates, and Cloudflare DNS records.'],
                    ['Databases', 'Inspect website databases, add expiring users, and copy data between websites.'],
                    ['Backups', 'Scheduled, verified backups to your own S3 storage, and restores you can follow.'],
                    ['Load balancers', 'Weighted pools across servers with health probes.'],
                ]],
                ['Costs', [
                    ['What you spend', 'Provider prices on every server, idle servers, per-project attribution and a monthly budget.'],
                ]],
            ],
            'questions' => [
                ['Whose cloud account is it?', 'Yours. We connect with a token you create and never hold the servers ourselves.'],
                ['Can I bring existing servers?', 'Yes. Import an Ubuntu server over SSH; we check it before managing it.'],
            ],
        ],
        'monitoring' => [
            'card' => ['badge' => 'Observe', 'tone' => 'success', 'icon' => 'pulse', 'chips' => ['HTTP, DNS, TLS, heartbeat', 'Errors and traces', 'Alerts and incidents'], 'preview' => ['title' => 'Current service health', 'subtitle' => 'Across your environments', 'status' => 'Operational', 'status_tone' => 'success', 'metrics' => [['Uptime', '99.99%'], ['Latest response', '184ms'], ['Open incidents', '0']]]],
            'eyebrow' => 'Understand production',
            'headline' => 'See health, errors and changes together.',
            'summary' => 'Uptime, heartbeat and queue checks, application telemetry and traces, and alerts that turn the signals that matter into incidents.',
            'groups' => [
                ['Checks', [
                    ['Uptime and more', 'HTTP, DNS, TLS, TCP, cron heartbeats and queue workers, with maintenance windows.'],
                    ['Status pages', 'Public status pages with incident updates and email subscriptions.'],
                ]],
                ['Telemetry', [
                    ['Errors and issues', 'Exceptions grouped into issues with their releases, assignments and a daily digest.'],
                    ['Traces and metrics', 'OpenTelemetry traces, logs and metrics, a service map, a metrics explorer and dashboards.'],
                    ['Releases', 'Deploys from Deploy (or your pipeline) mark releases, so problems point at what changed.'],
                ]],
                ['Respond', [
                    ['Alerts and escalations', 'Alert rules and service-level objectives, email, Slack and webhook destinations, and escalation steps.'],
                    ['Incidents', 'Timelines with acknowledgements, notes and assignments.'],
                ]],
            ],
            'questions' => [
                ['Do my agents need changing?', 'No. Ingest, OpenTelemetry, heartbeat and queue endpoints keep their addresses and formats.'],
            ],
        ],
        'analytics' => [
            'card' => ['badge' => 'Understand', 'tone' => 'warning', 'icon' => 'chart', 'chips' => ['No cookies', 'Goals and sources', 'Releases beside traffic'], 'preview' => ['title' => 'This week', 'subtitle' => 'storefront.com', 'status' => '+18.4%', 'status_tone' => 'info', 'metrics' => [['Visitors', '12.8K'], ['Pageviews', '41.2K'], ['Conversions', '316']]]],
            'eyebrow' => 'Understand your audience',
            'headline' => 'Understand the work your website is doing.',
            'summary' => 'A focused, privacy-friendly view of visitors, pages, sources and goals, without a noisy dashboard.',
            'groups' => [
                ['Reports', [
                    ['Traffic', 'Visitors, pageviews, pages, sources, campaigns, devices and browsers.'],
                    ['Goals', 'Conversion goals on pages or events, with their history.'],
                    ['Exports', 'Download your data when you need it.'],
                ]],
                ['Privacy', [
                    ['No cookies', 'Visitor hashes rotate daily, so nobody is followed from one day to the next.'],
                    ['A small tracker', 'One script, the same /tracker/v1.js as before.'],
                ]],
            ],
            'questions' => [
                ['Do I need a cookie banner?', 'The tracker sets no cookies and stores no personal data; check your own obligations with your advisers.'],
            ],
        ],
    ],
];
