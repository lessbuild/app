<?php

declare(strict_types=1);

// The help centre: short task guides, grouped by service. Each step names the page it happens on, so keep them in step
// with the navigation when pages move.
return [
    'groups' => [
        'platform' => ['title' => 'Your account', 'summary' => 'Projects, your team, billing, security and the API.', 'icon' => 'layers'],
        'infrastructure' => ['title' => 'Infrastructure', 'summary' => 'Providers, servers, websites, services, storage and backups.', 'icon' => 'server'],
        'deploy' => ['title' => 'Deploy', 'summary' => 'Repositories, environments, previews, scaling and releases.', 'icon' => 'cloud-upload'],
        'monitoring' => ['title' => 'Monitoring', 'summary' => 'Checks, telemetry, alerts, incidents and status pages.', 'icon' => 'pulse'],
        'security' => ['title' => 'Security', 'summary' => 'Scans, findings, servers, domains, attacks and compliance.', 'icon' => 'shield-check'],
        'analytics' => ['title' => 'Analytics', 'summary' => 'Sites, the tracker, reports, exploring, sharing and your data.', 'icon' => 'chart'],
    ],

    'guides' => [
        'getting-started' => [
            'group' => 'platform',
            'title' => 'Get started',
            'summary' => 'Create a project, turn on the services it needs, and find your way around.',
            'steps' => [
                ['Create a project', 'From Projects, choose New project. A project is one application or site; it gets production, staging and development environments to start with.'],
                ['Turn on services', 'In the project’s settings, switch on Deploy, Infrastructure, Monitoring or Analytics. Each appears in the project’s sidebar once it’s on.'],
                ['Find anything', 'Press Ctrl K (or ⌘ K) to open search from any page, and jump straight to a project, server or page.'],
                ['Follow what happens', 'The bell opens your notifications. Choose which ones you get in Your settings → Notifications.'],
            ],
        ],
        'team-and-access' => [
            'group' => 'platform',
            'title' => 'Invite your team and set access',
            'summary' => 'Add people to the account, give them a role, and choose which services they can use.',
            'steps' => [
                ['Invite someone', 'Go to Account → Members and choose Invite someone. Pick a role: owners and admins manage the account, members do day-to-day work, and viewers can only look.'],
                ['Limit services', 'On the member’s row, choose the services they can use. People only see the services you give them.'],
                ['Review changes', 'Account → Audit log records invitations, role changes, deletions and other important actions, with who made them.'],
            ],
        ],
        'billing' => [
            'group' => 'platform',
            'title' => 'Plans and billing',
            'summary' => 'Choose a plan for each service; they’re all billed together on one subscription.',
            'steps' => [
                ['Pick plans', 'Account → Billing shows each service’s plan. Every service has a free tier; upgrade only the ones that need more.'],
                ['Pay', 'Checkout and card details are handled by Stripe. Invoices and payment methods are in the billing portal, linked from the billing page.'],
                ['Change or cancel', 'Change a plan whenever you like. A cancelled plan stays active until the end of the period you’ve paid for, then moves to the free tier.'],
                ['Pay as you go', 'On a paid monthly plan, open the service’s tab on the billing page and turn on Pay as you go. Usage past your allowance is billed per unit instead of stopping, and you can set a monthly spend cap.'],
            ],
        ],
        'account-security' => [
            'group' => 'platform',
            'title' => 'Keep your account secure',
            'summary' => 'Two-factor authentication, passkeys, sessions and account-wide security rules.',
            'steps' => [
                ['Turn on two-factor authentication', 'Your settings → Security. Scan the code with an authenticator app, and keep the recovery codes somewhere safe.'],
                ['Add a passkey', 'On the same page, add a passkey to sign in with your device’s fingerprint, face or PIN.'],
                ['Check your sessions', 'Your settings → Sessions lists where you’re signed in and your recent sign-ins. Sign out anything you don’t recognise.'],
                ['Set rules for everyone', 'Owners can require two-factor authentication, limit sign-in to certain email domains or IP addresses, sign people out after a period of inactivity, and set up single sign-on, all from Account → Security.'],
            ],
        ],
        'api' => [
            'group' => 'platform',
            'title' => 'Use the API',
            'summary' => 'Automate deploys and more with API tokens.',
            'steps' => [
                ['Create a token', 'Account → API tokens → Create an API token. Choose only the abilities it needs and an expiry date. The token is shown once, so copy it straight away.'],
                ['Call the API', 'Send it as a bearer token. The API reference lists every endpoint, and the OpenAPI document can generate a client for you.'],
                ['Old scripts', 'Deployer API v1 scripts keep working unchanged, including old numeric IDs.'],
            ],
        ],
        'connect-a-provider' => [
            'group' => 'infrastructure',
            'title' => 'Connect a cloud provider',
            'summary' => 'Let BuildPusher create servers in your DigitalOcean, Hetzner Cloud or Vultr account.',
            'steps' => [
                ['Create a token at the provider', 'In your provider’s dashboard, create an API token with read and write access.'],
                ['Add it here', 'Account → Providers → Add a provider. Choose the provider, paste the token and save. We check it straight away.'],
                ['Source control and DNS', 'GitHub, GitLab, Bitbucket and Cloudflare are added the same way, from the same page.'],
            ],
        ],
        'create-a-server' => [
            'group' => 'infrastructure',
            'title' => 'Create a server',
            'summary' => 'Provision a server in your cloud account and follow its setup live.',
            'steps' => [
                ['Start', 'In your project, go to Infrastructure → Servers → Create a server.'],
                ['Choose what it’s for', 'Pick a provider, the server type (an app server runs PHP, Node, Caddy, MySQL and Redis together), a region and a size. Add recipes to run your own scripts once it’s set up.'],
                ['Watch it come up', 'The server page follows setup live: first waiting for the provider to give it an address, then each installation step. It usually takes a few minutes.'],
                ['Already have a server?', 'Import an Ubuntu server over SSH instead; we check it before managing it.'],
            ],
        ],
        'add-a-website' => [
            'group' => 'infrastructure',
            'title' => 'Add a website',
            'summary' => 'Host a website on a server, with a domain, TLS and a database.',
            'steps' => [
                ['Create it', 'Infrastructure → Websites → Add a website. Choose the server and the website’s main domain.'],
                ['Point the domain', 'Point the domain’s DNS at the server’s IP address, or connect Cloudflare and we’ll create the record. A certificate is issued automatically.'],
                ['Aliases and redirects', 'Add more domains on the website’s page, as aliases or as redirects to the main one.'],
                ['Database', 'App-server websites get their own MySQL database. You can inspect it, add users that expire, and copy data from another website.'],
            ],
        ],
        'server-tasks' => [
            'group' => 'infrastructure',
            'title' => 'Cron jobs, processes, firewall and services',
            'summary' => 'Schedule commands, keep workers running, open ports and install search or cache on a server.',
            'steps' => [
                ['Cron jobs', 'On the server’s Cron jobs tab, add a command and a schedule (or pick a preset such as every minute). It runs as the server’s user.'],
                ['Processes', 'The Processes tab keeps commands running and restarts them if they stop: queue workers, Horizon, Reverb, Pulse or your own. Restart one after a deploy from its row.'],
                ['Firewall', 'The Firewall tab opens or closes ports, for everyone or only some addresses. Turn on Private network there to let your other servers in the same region connect.'],
                ['Services', 'The Services tab installs Meilisearch, Typesense or Redis, listening on the server only or on the private network. Its address and key are shown once it’s running.'],
            ],
        ],
        'website-tools' => [
            'group' => 'infrastructure',
            'title' => 'Web server, files, logs and CDN',
            'summary' => 'Adjust how a website is served, look at its files and logs, and put it behind a CDN.',
            'steps' => [
                ['Web server settings', 'On the website’s Settings tab, add your own Caddy settings under Web server (Caddy). They’re checked first, and the previous settings come back if they’re invalid.'],
                ['WebSockets', 'Under WebSockets (Laravel Reverb) on the Settings tab, set up Reverb in one step: it runs as a process and the web server forwards its traffic.'],
                ['Files and logs', 'The Files tab lets you browse the website’s files, follow the end of a log, and search the logs for a phrase.'],
                ['CDN and firewall', 'On the Domains tab, choose CDN and firewall on a domain to serve it through Cloudflare, block countries or addresses, and rate-limit requests. The cache is cleared after every deploy.'],
            ],
        ],
        'storage-buckets' => [
            'group' => 'infrastructure',
            'title' => 'Storage buckets',
            'summary' => 'Object storage for your apps, from S3, Spaces, R2, Google Cloud Storage and others.',
            'steps' => [
                ['Add a bucket', 'Infrastructure → Storage → Add a bucket. Create a new one or connect an existing bucket with its access key.'],
                ['Use it in an app', 'Attach the bucket to an environment and its details are added as environment variables (the usual AWS_ names), ready for Laravel’s s3 disk.'],
            ],
        ],
        'backups' => [
            'group' => 'infrastructure',
            'title' => 'Back up and restore',
            'summary' => 'Scheduled, verified backups to your own storage.',
            'steps' => [
                ['Add storage', 'Connect S3-compatible storage from Account → Providers.'],
                ['Schedule backups', 'On the website’s page, add a backup schedule. Every backup is checked after it’s written.'],
                ['Restore', 'Infrastructure → Backups lists every backup. Choose Restore and follow its progress.'],
            ],
        ],
        'deploy-from-git' => [
            'group' => 'deploy',
            'title' => 'Deploy from Git',
            'summary' => 'Connect a repository and deploy it to a website on every push.',
            'steps' => [
                ['Connect the repository', 'Deploy → Repositories → Connect a repository. Choose the provider, the repository and branch, the environment, and the website it deploys to.'],
                ['Set up the build', 'Add your build commands, and the environment’s variables (see Environments and variables).'],
                ['Deploy', 'Deploy from the repository page, or turn on deploy on push. Each deploy is a new release, and the previous ones are kept.'],
                ['Follow it', 'The deploy page shows each step’s log as it runs, and the release is marked in Monitoring and Analytics once it’s live.'],
            ],
        ],
        'use-the-cli' => [
            'group' => 'deploy',
            'title' => 'Deploy from the command line',
            'summary' => 'Deploy, follow logs and roll back from your terminal or CI with the BuildPusher CLI.',
            'steps' => [
                ['Install it', 'Run curl -fsSL https://buildpusher.com/cli/install.sh | sh. It’s one PHP file, so it needs PHP 8.1 or later.'],
                ['Log in', 'Create an API token with the Deploy scopes under Account → API tokens, then run buildpusher login and paste it. In CI, set BUILDPUSHER_TOKEN instead.'],
                ['Deploy', 'Run buildpusher deploy <project> [environment] --wait to deploy and follow the log; add --ref=v1.4.0 to deploy a tag, branch or commit. It exits non-zero if the deploy fails, so CI jobs fail with it.'],
                ['Follow and undo', 'buildpusher status lists recent deploys, buildpusher logs <id> --follow streams one, and buildpusher rollback <id> goes back to the release a deploy shipped.'],
                ['Set defaults', 'Put {"project": "shop", "environment": "staging"} in .buildpusher.json at the root of your repository to leave them off each command.'],
                ['In GitHub Actions or code', 'Deploy from a workflow with the BuildPusher GitHub Action, or from your own code with the PHP (buildpusher/sdk) and JavaScript (@buildpusher/sdk) SDKs.'],
            ],
        ],
        'environments-and-variables' => [
            'group' => 'deploy',
            'title' => 'Environments and variables',
            'summary' => 'Configure each environment: variables, workers, how deploys run, and recipes.',
            'steps' => [
                ['Open the environment', 'Deploy → Environments, then choose production, staging or development.'],
                ['Variables', 'On the Variables tab, add environment variables and secrets. They’re encrypted, and previews never inherit them.'],
                ['How deploys run', 'Choose the strategy (atomic, rolling, blue-green or canary), approvals, deploy locks and weekly windows.'],
                ['Workers and recipes', 'Run queue workers and schedulers from the Workers tab, and scripts on the environment’s servers from the Recipes tab.'],
            ],
        ],
        'scaling-and-maintenance' => [
            'group' => 'deploy',
            'title' => 'Autoscaling, maintenance mode and regions',
            'summary' => 'Scale with demand, take the site down for planned work, and see where it runs.',
            'steps' => [
                ['Autoscale', 'On the environment’s How deploys run tab, tick Scale automatically and set a target CPU. Replicas are added and removed within the minimum and maximum you set.'],
                ['Maintenance mode', 'On the Controls tab, choose Turn on maintenance mode. Visitors see a “back soon” page; open the secret path shown there once to get in yourself. It stays on through deploys until you turn it off.'],
                ['Regions', 'The Controls tab lists every website the environment deploys to and its region. To run in another region, add a website on a server there and connect the repository to it.'],
            ],
        ],
        'previews' => [
            'group' => 'deploy',
            'title' => 'Preview pull requests',
            'summary' => 'Give every pull request its own running copy.',
            'steps' => [
                ['Turn previews on', 'On the repository’s page, turn on previews. Each new pull request gets its own website and environment.'],
                ['Share it', 'GitHub App repositories get a check run and a comment with the preview’s address.'],
                ['Secrets', 'Previews start without secrets; approve the ones a preview needs for its exact revision.'],
                ['Start with data', 'On the repository’s page, choose a website to copy the database from, and each preview starts with a copy of it.'],
                ['Clean-up', 'A preview is removed when its pull request is closed or merged.'],
            ],
        ],
        'rollback-and-promotion' => [
            'group' => 'deploy',
            'title' => 'Roll back, compare and promote',
            'summary' => 'Go back to a known release, see what changed, and move a tested build forward.',
            'steps' => [
                ['Roll back', 'On any earlier successful build, choose Roll back. The previous release is switched back in seconds.'],
                ['Compare', 'Choose Compare on a build to see the commits and settings that changed since another build.'],
                ['Promote', 'Promote a build that passed in staging to production; it keeps its history.'],
            ],
        ],
        'monitor-uptime' => [
            'group' => 'monitoring',
            'title' => 'Monitor uptime',
            'summary' => 'Check your sites and jobs, and get told when something breaks.',
            'steps' => [
                ['Add a monitor', 'Monitoring → Monitors → Add a monitor. Choose HTTP, DNS, TLS, TCP, a heartbeat for scheduled jobs, or a queue.'],
                ['Choose where alerts go', 'Monitoring → Alerts → Destinations. Add email, Slack, Microsoft Teams, PagerDuty, Discord or a webhook.'],
                ['Planned work', 'Add a maintenance window before planned work, so expected downtime doesn’t open an incident.'],
            ],
        ],
        'send-telemetry' => [
            'group' => 'monitoring',
            'title' => 'Send errors, traces and metrics',
            'summary' => 'Instrument your application so problems point at the release that caused them.',
            'steps' => [
                ['Get a key', 'Monitoring → Setup. Create an ingestion key for the environment.'],
                ['Send data', 'Use the SDK snippet on the setup page, or send OpenTelemetry traces, logs and metrics to the OTLP endpoint shown there.'],
                ['Investigate', 'Errors are grouped into Issues; Events, Metrics and Traces let you dig in, and Releases show what changed.'],
                ['Link to deploys', 'Every trace shows the deploy that was live when it ran, and each deploy links to its requests and traces, so you can see what a release changed.'],
            ],
        ],
        'incidents-and-status-pages' => [
            'group' => 'monitoring',
            'title' => 'Incidents and status pages',
            'summary' => 'Respond to problems and keep your users informed.',
            'steps' => [
                ['Respond', 'Monitoring → Incidents. Acknowledge, assign, and add notes; the timeline records it all.'],
                ['Escalate', 'Set escalation steps on an alert rule, so someone else is told if nobody responds.'],
                ['Publish a status page', 'Monitoring → Status pages → Create. Choose the monitors to show, post updates, and let people subscribe by email.'],
            ],
        ],
        'security-basics' => [
            'group' => 'security',
            'title' => 'Get started with Security',
            'summary' => 'Turn on Security, read the findings and fix what matters first.',
            'steps' => [
                ['Turn it on', 'In the project’s settings, switch on Security. Each check runs straight away, then as often as your plan allows (weekly on Free, daily on Pro, every six hours on Team).'],
                ['Read the overview', 'Security shows a score out of 100 and a grade, the checks and when they last ran, and the most serious open findings.'],
                ['Fix and confirm', 'Each finding says how to fix it. After fixing, choose Scan now on the check; the finding resolves when the scan no longer sees it.'],
                ['Ignore with a reason', 'For a false positive or an accepted risk, choose Ignore and say why. It stays ignored through later scans until someone reopens it.'],
                ['Domains and email', 'The domain check looks at every domain in the project: certificates, TLS versions, the redirect to HTTPS, security headers, SPF and DMARC, and CNAMEs left pointing at services that could be taken over.'],
            ],
        ],
        'add-analytics' => [
            'group' => 'analytics',
            'title' => 'Add analytics to a website',
            'summary' => 'Count visitors without cookies, and measure the goals that matter.',
            'steps' => [
                ['Add the site', 'Analytics → Sites → Add a site, with the domain it runs on.'],
                ['Install the tracker', 'Copy the one-line script from the site’s page into your website’s head. Visits appear within a minute.'],
                ['Set goals', 'Analytics → Goals. Count visits to a page, or an event you send, as a conversion.'],
                ['Count more', 'Add data-outbound, data-downloads, data-vitals or data-not-found to the script for links, downloads, page speed and missing pages. Engagement (time on page and scroll depth) is counted automatically.'],
                ['Custom properties', 'To break events down by your own details, list the property names in the site’s settings, then send them: window.buildpusher.track(\'signup\', {plan: \'pro\'}).'],
            ],
        ],
        'explore-analytics' => [
            'group' => 'analytics',
            'title' => 'Explore your traffic',
            'summary' => 'Choose any period, compare it, save segments, and dig into paths, retention and sales.',
            'steps' => [
                ['Pick a period', 'On the Analytics overview, choose a preset or set From and To dates, and compare with the previous period, the same period last year, or nothing.'],
                ['Filter and save', 'Click any page, channel, country, city, browser or campaign to filter by it, then save the filters as a view (a segment) to come back to.'],
                ['Explore', 'Analytics → Explore has Insights (the biggest changes), Paths (where people came from and went next), Properties, Items (e-commerce) and Retention.'],
                ['Retention', 'Add data-retention to the script to recognise returning browsers. It keeps a random ID in the browser, so ask for consent where the law requires it.'],
                ['Notes', 'Choose Add a note on the overview to mark a launch or outage on the chart.'],
            ],
        ],
        'share-analytics' => [
            'group' => 'analytics',
            'title' => 'Reports, alerts and sharing',
            'summary' => 'Send regular reports, get told about spikes, and share or embed a report.',
            'steps' => [
                ['Reports and alerts', 'On the site’s page, under Reports and alerts, add a weekly or monthly report, or a traffic spike alert, by email or to a Slack incoming webhook.'],
                ['Share', 'Under Share the report, create a read-only link, with a password if you like.'],
                ['Embed', 'A link without a password comes with an iframe snippet to put the report on another page.'],
                ['Export', 'Export a report as CSV from the overview, or read it through the API.'],
            ],
        ],
        'analytics-data' => [
            'group' => 'analytics',
            'title' => 'Import, export and your own domain',
            'summary' => 'Bring your Google Analytics history, send raw data to BigQuery, and avoid ad blockers.',
            'steps' => [
                ['Import from Google Analytics', 'On the site’s page, choose Connect Google Analytics, then pick the GA4 property and dates. Only days before your site’s own data are imported.'],
                ['Raw data export', 'Under Raw data export, choose one of the project’s storage buckets. Each night, the previous day’s events are written as gzipped JSON lines, one file per day, ready for BigQuery or Athena.'],
                ['Your own domain', 'Under Send through your own domain, copy the proxy rules for Caddy or Nginx and the matching snippet, so the tracker loads from your site and ad blockers don’t stop it.'],
            ],
        ],
    ],
];
