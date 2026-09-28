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

    // One entry per service, laid out like the Signal product pages: a card and explorer panel for the home page's
    // product suite, and the hero preview, highlights, capability groups, workflow, guardrails and questions for its
    // own page. Preview values are illustrative sample data.
    'services' => [
        'deploy' => [
            'accent' => 'deploy',
            'icon' => 'cloud-upload',
            'eyebrow' => 'Build and ship',
            'headline' => 'Deploy with clarity. Recover with confidence.',
            'summary' => 'Release from GitHub, GitLab or Bitbucket to servers you own, with approvals, safe strategies, previews and a rollback that’s always one click away.',
            'card_summary' => 'Release from Git to your own servers, with previews, approvals and a recovery point for every deploy.',
            'card_features' => ['Git-connected releases', 'Previews per pull request', 'Blue-green, canary and rolling deploys', 'Rollback in seconds'],
            'suite' => [
                'title' => 'Ship a controlled release',
                'description' => 'Connect a repository, review what will change, promote an approved build, and keep a known good release to go back to.',
                'features' => ['Pull-request previews that never inherit secrets', 'Atomic, rolling, blue-green and canary releases', 'Approvals, deploy locks and exact-revision rollback'],
            ],
            'capabilities' => ['GitHub', 'GitLab', 'Bitbucket', 'DigitalOcean', 'Hetzner Cloud', 'Vultr', 'Deployer API v1'],
            'preview' => [
                'title' => 'Deployment #1841',
                'context' => 'Storefront · production',
                'description' => 'A release record with revision, rollout and verification in one place.',
                'status' => 'Health verified',
                'status_tone' => 'success',
                'metrics' => [['Revision', 'a71c8ef'], ['Strategy', 'Blue-green'], ['Recovery', 'Available']],
                'activity_label' => 'Release timeline',
                'activity' => [
                    ['Approval', 'Approved by a second team member', 'Passed'],
                    ['Release activated', 'New revision serving production traffic', 'Complete'],
                    ['Health verification', 'Checks passed after the switch', 'Verified'],
                ],
            ],
            'highlights_heading' => 'A delivery control plane with the recovery path in view.',
            'highlights' => [
                ['icon' => 'code', 'title' => 'From repository to release', 'text' => 'Connect a Git provider, get approval, then follow the release through to healthy traffic.'],
                ['icon' => 'layers', 'title' => 'A stack per pull request', 'text' => 'Each pull request gets its own website, environment and deploys, removed when it closes.'],
                ['icon' => 'clock', 'title' => 'A visible recovery path', 'text' => 'Previous releases stay on the server, so going back to a known revision takes seconds.'],
            ],
            'groups' => [
                ['label' => 'Release', 'title' => 'Make every deployment traceable', 'description' => 'From the first commit to a verified release, every step stays visible and recoverable.', 'features' => [
                    ['Release per build', 'Each deploy is its own release directory; the previous ones stay for instant rollbacks.'],
                    ['Safe strategies', 'Blue-green, canary and rolling deploys, with health observation and automatic rollback.'],
                    ['Approvals and controls', 'Approval by someone else, deploy locks with a reason, and weekly deploy windows.'],
                    ['Promotion', 'Promote a tested build from preview to development, staging and production, keeping its lineage.'],
                ]],
                ['label' => 'Previews', 'title' => 'Review changes before they merge', 'description' => 'Every pull request can have a running copy of the application, with secrets kept out of it.', 'features' => [
                    ['A stack per pull request', 'Each pull request gets its own website, environment and deploys, removed when it closes.'],
                    ['Secrets stay safe', 'Previews never inherit secrets; you approve chosen ones for an exact revision.'],
                    ['GitHub checks', 'GitHub App repositories get a check run and a comment that follows the preview.'],
                ]],
                ['label' => 'Automate', 'title' => 'Act safely when it matters', 'description' => 'Turn repeatable operations into controlled, recorded work.', 'features' => [
                    ['Scheduled deploys and tasks', 'Cron schedules in your time zone, task history with output, and alerts when a task starts failing.'],
                    ['Workers and hibernation', 'Queue workers and schedulers as services, scaling schedules, and idle environments that sleep until the next request.'],
                    ['Environment recipes', 'Scripts that run on an environment’s servers, in order, and on new websites if you ask.'],
                    ['Configuration as code', 'Plan, review and apply environment configuration documents, from the dashboard or the API.'],
                ]],
                ['label' => 'Integrate', 'title' => 'Keep your scripts working', 'description' => 'Everything the dashboard does is available to your pipeline.', 'features' => [
                    ['Deployer API v1', 'Deploy, roll back, promote, scale and manage variables from scripts, with scoped API tokens.'],
                    ['Signed webhooks', 'Push to deploy from GitHub, GitLab and Bitbucket, with every delivery verified.'],
                ]],
            ],
            'workflows_heading' => 'Connect. Release. Recover.',
            'workflows_intro' => 'Keep the path from a source change to production visible to the whole team.',
            'workflows' => [
                ['Launch with confidence', 'Connect a repository, pick the servers, and verify the first release.'],
                ['Review before merging', 'Open a pull request, share its preview, and promote the tested build.'],
                ['Recover with evidence', 'See which release an incident started with, and switch back to the one before it.'],
            ],
            'guardrails_title' => 'Keep production actions accountable.',
            'guardrails_description' => 'Approvals, locks and windows decide what may ship; the audit log records who shipped it.',
            'guardrails' => ['Encrypted variables and keys', 'Approval by someone else', 'Deploy locks and windows', 'Signed webhooks', 'Retained releases', 'Exact-revision rollback'],
            'together' => [
                ['Monitoring', 'Every live deploy becomes a release marker, so issues and incidents point at the release that caused them.'],
                ['Analytics', 'Reports list the releases in the period beside the traffic.'],
            ],
            'questions' => [
                ['Where does my application run?', 'On servers in the cloud accounts you connect (DigitalOcean, Hetzner Cloud or Vultr), or servers you import.'],
                ['Can I go back to an earlier release?', 'Yes. Retained releases switch back in seconds, and failed live deploys can roll back on their own.'],
                ['Do my old scripts keep working?', 'Yes. The Deployer API v1 keeps its paths, fields and status codes, including old numeric IDs.'],
                ['How are secrets handled?', 'Environment variables, provider tokens, keys and scripts are encrypted at rest, and previews never inherit them.'],
            ],
        ],
        'infrastructure' => [
            'accent' => 'infrastructure',
            'icon' => 'server',
            'eyebrow' => 'Servers you own',
            'headline' => 'Provision without the guesswork.',
            'summary' => 'Create and import servers, host websites with domains and TLS, back them up, and keep an eye on what it all costs.',
            'card_summary' => 'Create servers in your own cloud accounts, host websites on them, and keep them backed up.',
            'card_features' => ['DigitalOcean, Hetzner Cloud and Vultr', 'Domains and automatic TLS', 'Verified backups to your storage', 'Costs per server and project'],
            'suite' => [
                'title' => 'Run servers you own',
                'description' => 'Create a server in your cloud account or import one, host websites on it, and follow every command that runs.',
                'features' => ['Setup stages you can follow', 'Commands, a browser terminal and diagnostics', 'Backups, restores and load balancers'],
            ],
            'capabilities' => ['DigitalOcean', 'Hetzner Cloud', 'Vultr', 'Cloudflare DNS', 'Ubuntu', 'S3-compatible storage'],
            'preview' => [
                'title' => 'web-1',
                'context' => 'Hetzner Cloud · fsn1 · Ubuntu 24.04',
                'description' => 'A server with its websites, resources and backups side by side.',
                'status' => 'Active',
                'status_tone' => 'success',
                'metrics' => [['Websites', '3'], ['Monthly cost', '€7.59'], ['Last backup', '2h ago']],
                'activity_label' => 'Recent work',
                'activity' => [
                    ['Certificate renewed', 'storefront.example · automatic', 'Done'],
                    ['Backup verified', 'Nightly database backup restored to a check', 'Verified'],
                    ['Recipe ran', 'Firewall rules applied', 'Complete'],
                ],
            ],
            'highlights_heading' => 'Your servers, set up the way you’d set them up.',
            'highlights' => [
                ['icon' => 'server', 'title' => 'Provision or import', 'text' => 'Create servers at DigitalOcean, Hetzner Cloud or Vultr, or import an Ubuntu server over SSH.'],
                ['icon' => 'globe', 'title' => 'Websites with TLS', 'text' => 'Primary domains, aliases and redirects, with automatic certificates and Cloudflare DNS.'],
                ['icon' => 'database', 'title' => 'Backups you can trust', 'text' => 'Scheduled backups to your own S3 storage, verified, with restores you can follow.'],
            ],
            'groups' => [
                ['label' => 'Servers', 'title' => 'Create, connect and operate', 'description' => 'Every server shows how it was set up and what has run on it since.', 'features' => [
                    ['Cloud provisioning', 'Create servers at DigitalOcean, Hetzner Cloud or Vultr, or import one over SSH, and follow each setup stage.'],
                    ['Commands and terminal', 'Run commands with history, open a browser terminal, and collect logs, metrics and diagnostics.'],
                    ['Recipes', 'Scripts that run on new servers, from your library or a gallery other teams share.'],
                ]],
                ['label' => 'Websites', 'title' => 'Host and protect websites', 'description' => 'Domains, databases and backups stay beside the website they belong to.', 'features' => [
                    ['Domains and TLS', 'Primary domains, aliases and redirects with automatic certificates, and Cloudflare DNS records.'],
                    ['Databases', 'Inspect website databases, add expiring users, and copy data between websites.'],
                    ['Backups', 'Scheduled, verified backups to your own S3 storage, and restores you can follow.'],
                    ['Load balancers', 'Weighted pools across servers with health probes.'],
                ]],
                ['label' => 'Costs', 'title' => 'Know what you spend', 'description' => 'Provider prices sit on every server, so costs never arrive as a surprise.', 'features' => [
                    ['Server costs', 'Provider prices on every server, and idle servers flagged.'],
                    ['Budgets', 'Per-project attribution and a monthly budget.'],
                ]],
            ],
            'workflows_heading' => 'Connect. Provision. Host.',
            'workflows_intro' => 'Go from a cloud account to a website serving traffic, one visible step at a time.',
            'workflows' => [
                ['Connect a provider', 'Add a token for your DigitalOcean, Hetzner Cloud or Vultr account.'],
                ['Create a server', 'Pick a size and region, and follow each setup stage until it’s ready.'],
                ['Add websites', 'Point a domain at it, get a certificate, and schedule backups.'],
            ],
            'guardrails_title' => 'Keep servers yours and changes recorded.',
            'guardrails_description' => 'We connect with tokens you create and can revoke. Commands, terminals and restores are recorded against the person who ran them.',
            'guardrails' => ['Encrypted provider tokens', 'Your cloud account', 'Recorded commands', 'Verified backups', 'Expiring database users', 'Signed setup callbacks'],
            'together' => [
                ['Deploy', 'Repositories deploy to the websites and servers you manage here.'],
                ['Monitoring', 'Server metrics and uptime checks share the same alerts and incidents.'],
            ],
            'questions' => [
                ['Whose cloud account is it?', 'Yours. We connect with a token you create and never hold the servers ourselves.'],
                ['Can I bring existing servers?', 'Yes. Import an Ubuntu server over SSH; we check it before managing it.'],
                ['Where do backups go?', 'To S3-compatible storage you own. Each backup is verified after it’s written.'],
            ],
        ],
        'monitoring' => [
            'accent' => 'monitor',
            'icon' => 'pulse',
            'eyebrow' => 'Understand production',
            'headline' => 'See health, errors and changes together.',
            'summary' => 'Uptime, heartbeat and queue checks, application telemetry and traces, and alerts that turn the signals that matter into incidents.',
            'card_summary' => 'Understand uptime, application behaviour and incidents across every environment you run.',
            'card_features' => ['HTTP, DNS, TLS, TCP, heartbeat and queue checks', 'Errors, traces, logs and metrics', 'Alerts, SLOs and escalations', 'Status pages with subscriptions'],
            'suite' => [
                'title' => 'Detect and respond',
                'description' => 'Bring scheduled checks, worker health and application telemetry into a response your team can follow.',
                'features' => ['Checks with maintenance windows', 'OpenTelemetry traces and grouped issues with their releases', 'Alert destinations, escalations and incident timelines'],
            ],
            'capabilities' => ['OpenTelemetry', 'HTTP, DNS, TLS, TCP', 'Heartbeats', 'Queue workers', 'SLOs', 'Email', 'Slack', 'Webhooks'],
            'preview' => [
                'title' => 'Service health',
                'context' => 'Storefront · production',
                'description' => 'Availability, telemetry and response activity in one service view.',
                'status' => 'Operational',
                'status_tone' => 'success',
                'metrics' => [['Uptime', '99.99%'], ['Latency', '142 ms'], ['Objective', 'On target']],
                'activity_label' => 'Recent signals',
                'activity' => [
                    ['HTTP check', 'storefront.example · 200 in 142 ms', 'Healthy'],
                    ['Latency alert rule', 'Trigger and recovery conditions set', 'Armed'],
                    ['Release a71c8ef', 'Marked from Deploy', 'Tracked'],
                ],
            ],
            'highlights_heading' => 'Follow a service from first signal through recovery.',
            'highlights' => [
                ['icon' => 'pulse', 'title' => 'Availability with history', 'text' => 'HTTP, DNS, TLS and TCP checks, queue workers and cron heartbeats, with maintenance windows beside the history.'],
                ['icon' => 'activity', 'title' => 'Application signals together', 'text' => 'Traces, metrics, logs and grouped issues, each with the release it arrived in.'],
                ['icon' => 'bell', 'title' => 'A response path for incidents', 'text' => 'Route alerts, escalate, acknowledge, and publish updates to a status page.'],
            ],
            'groups' => [
                ['label' => 'Checks', 'title' => 'Know when a service changes', 'description' => 'Scheduled checks with history, recovery and planned work in context.', 'features' => [
                    ['Uptime and more', 'HTTP, DNS, TLS, TCP, cron heartbeats and queue workers, with maintenance windows.'],
                    ['Status pages', 'Public status pages with incident updates and email subscriptions.'],
                ]],
                ['label' => 'Telemetry', 'title' => 'Follow a request through the system', 'description' => 'The application signals that explain what users see.', 'features' => [
                    ['Errors and issues', 'Exceptions grouped into issues with their releases, assignments and a daily digest.'],
                    ['Traces and metrics', 'OpenTelemetry traces, logs and metrics, a service map, a metrics explorer and dashboards.'],
                    ['Releases', 'Deploys from Deploy (or your pipeline) mark releases, so problems point at what changed.'],
                ]],
                ['label' => 'Respond', 'title' => 'Turn important signals into clear work', 'description' => 'Explicit alert conditions, routed notifications and a timeline for every incident.', 'features' => [
                    ['Alerts and escalations', 'Alert rules and service-level objectives, email, Slack and webhook destinations, and escalation steps.'],
                    ['Incidents', 'Timelines with acknowledgements, notes and assignments.'],
                ]],
            ],
            'workflows_heading' => 'Instrument. Detect. Respond.',
            'workflows_intro' => 'Put availability, telemetry and the team’s response into a practical sequence.',
            'workflows' => [
                ['Instrument the service', 'Create an application, add an ingestion key, and send telemetry or OpenTelemetry data.'],
                ['Track availability', 'Add checks and heartbeats, and record maintenance windows before planned work.'],
                ['Respond and learn', 'Route alerts to your team, work the incident timeline, and compare against your objectives.'],
            ],
            'guardrails_title' => 'Keep signals useful and response traceable.',
            'guardrails_description' => 'Control how telemetry arrives, how alerts are routed, and who can see what happened.',
            'guardrails' => ['Scoped ingestion keys', 'Trigger and recovery conditions', 'Cooldowns and deduplication', 'Delivery history and retries', 'Roles and audit history', 'Unchanged ingest endpoints'],
            'together' => [
                ['Deploy', 'Issues and incidents show the release that was live when they started.'],
                ['Analytics', 'Incident windows sit beside the traffic they affected.'],
            ],
            'questions' => [
                ['What can Monitoring collect?', 'Scheduled HTTP, DNS, TLS and TCP checks, queue and heartbeat checks, and application errors, traces, logs and metrics, including OpenTelemetry.'],
                ['Can I share service health publicly?', 'Yes. Status pages publish the services you choose, with incident updates and email subscriptions.'],
                ['Do my agents need changing?', 'No. Ingest, OpenTelemetry, heartbeat and queue endpoints keep their addresses and formats.'],
            ],
        ],
        'analytics' => [
            'accent' => 'analytics',
            'icon' => 'chart',
            'eyebrow' => 'Understand your audience',
            'headline' => 'Understand the work your website is doing.',
            'summary' => 'A focused, privacy-friendly view of visitors, pages, sources and goals, without a noisy dashboard.',
            'card_summary' => 'See how visitors find your site, what they read, and which visits turn into outcomes.',
            'card_features' => ['No cookies', 'Pages, sources, campaigns and devices', 'Goals on pages or events', 'Releases beside traffic'],
            'suite' => [
                'title' => 'Measure what changed',
                'description' => 'Explore traffic, sources and goals, with the releases you shipped marked on the same reports.',
                'features' => ['Pages, referrers, campaigns and devices', 'Page and event goals', 'Releases from Deploy in every period'],
            ],
            'capabilities' => ['Cookieless', 'UTM campaigns', 'Page and event goals', 'Devices and browsers', 'CSV exports', 'Release context'],
            'preview' => [
                'title' => 'Site overview',
                'context' => 'storefront.example · last 30 days',
                'description' => 'Traffic estimates beside sources, pages and goals.',
                'status' => 'Sample report',
                'status_tone' => 'info',
                'metrics' => [['Visitors', '1,248'], ['Top source', 'Search'], ['Conversions', '37']],
                'activity_label' => 'Traffic and change',
                'activity' => [
                    ['Top page', '/pricing · 412 views', 'Content'],
                    ['Release a71c8ef', 'Deployed to production', 'Change'],
                    ['Goal: Sign up', '37 conversions · 3.0%', 'Outcome'],
                ],
            ],
            'highlights_heading' => 'Useful audience answers without a noisy dashboard.',
            'highlights' => [
                ['icon' => 'chart', 'title' => 'Traffic at a glance', 'text' => 'Visitors, pageviews and how they changed over the range you pick.'],
                ['icon' => 'globe', 'title' => 'Sources and campaigns', 'text' => 'See where visitors come from and what they read.'],
                ['icon' => 'check', 'title' => 'Goals tied to outcomes', 'text' => 'Count visits to a page or an explicit event as a conversion.'],
                ['icon' => 'activity', 'title' => 'Changes beside traffic', 'text' => 'Releases from Deploy are listed with the period they fall in.'],
            ],
            'groups' => [
                ['label' => 'Reports', 'title' => 'See how people arrive and what they read', 'description' => 'The sources, campaigns and pages that shape a visit.', 'features' => [
                    ['Traffic', 'Visitors, pageviews, pages, sources, campaigns, devices and browsers.'],
                    ['Goals', 'Conversion goals on pages or events, with their history.'],
                    ['Exports', 'Download your data when you need it.'],
                ]],
                ['label' => 'Privacy', 'title' => 'Learn from trends, not people', 'description' => 'Useful numbers without building a profile of anyone.', 'features' => [
                    ['No cookies', 'Visitor hashes rotate daily, so nobody is followed from one day to the next.'],
                    ['A small tracker', 'One script, the same /tracker/v1.js as before.'],
                ]],
            ],
            'workflows_heading' => 'Measure. Explore. Learn.',
            'workflows_intro' => 'Turn a website into reports your team can use to make its next decision.',
            'workflows' => [
                ['Add a website', 'Create a site and add the one-line tracker.'],
                ['Explore', 'Choose a range and compare pages, sources and campaigns.'],
                ['Measure outcomes', 'Define goals, and see which releases came before a change.'],
            ],
            'guardrails_title' => 'Keep collection and reporting clear.',
            'guardrails_description' => 'Analytics reports useful website trends without cookies or cross-site profiles.',
            'guardrails' => ['No cookies', 'Daily-rotating visitor hashes', 'No cross-site profile', 'Per-plan retention', 'Roles and audit history', 'Exports on request'],
            'together' => [
                ['Deploy', 'Releases are listed beside the traffic in the same period.'],
                ['Monitoring', 'Incidents sit beside the traffic they affected.'],
            ],
            'questions' => [
                ['Do I need a cookie banner?', 'The tracker sets no cookies and stores no personal data; check your own obligations with your advisers.'],
                ['Does the tracker address change?', 'No. Sites keep using /tracker/v1.js.'],
            ],
        ],
    ],
];
