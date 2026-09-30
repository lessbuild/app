<?php

declare(strict_types=1);

// What's new, newest first. Each entry: date, title, and the changes people will notice.
return [
    ['date' => '2026-10-01', 'title' => 'Analytics: lighter, and more in your control', 'changes' => [
        'The tracker is under half its old size (about 2 KB compressed); link, download and page speed tracking load only when you turn them on.',
        'Leave out visits from your office or other addresses, count clicks on any element by adding a CSS class, and track single-page apps that route with #/paths.',
        'Give a client view-only access to one site with their own link, without adding them to your account.',
        'Send pageviews and events from your server through the API and SDKs, for things that happen outside the browser.',
        'New in Explore: first- and last-touch attribution for goals and revenue, click maps, and form analytics that show which field people give up on.',
        'A/B tests: call buildpusher.variant() on your page, pick the goal that decides it, and Explore shows each variant’s conversion rate, lift and whether the difference is statistically significant.',
        'Group pages (such as everything under /blog), see what people search for on your site and which searches find nothing, and keep referrer spam and bots out, with a count of what was left out.',
    ]],
    ['date' => '2026-09-30', 'title' => 'Security, a fifth service', 'changes' => [
        'A security score for each project, with every finding explained and a fix beside it.',
        'Vulnerable Composer and npm packages, leaked keys in code and logs, and weak domains (certificates, TLS, headers, SPF, DMARC, and DNS records that could be taken over).',
        'A deploy gate that stops releases with known critical vulnerabilities before they go live.',
        'Server hardening with one-click fixes, weekly security update windows, and SSH access with each person’s own keys.',
        'Attackers blocked automatically from access logs, plus Cloudflare security level, bot fight mode and under attack mode.',
        'Access reviews and a compliance evidence pack for SOC 2 and ISO 27001.',
    ]],
    ['date' => '2026-09-30', 'title' => 'Analytics: explore, compare and share', 'changes' => [
        'Pick any dates and compare with the period before or the same period last year. Save filter sets as segments.',
        'New in reports: channels, regions and cities, screen sizes, browser and system versions, UTM term and content, and time on page and scroll depth for each page.',
        'A new Explore page: what changed most, where visitors go before and after a page, custom property breakdowns, items sold, and weekly retention.',
        'Email or Slack a weekly or monthly report, and get an alert when a lot of people are on the site at once.',
        'Embed a shared report on another site, serve the tracker from your own domain so blockers don’t stop it, and add notes to the chart.',
        'Import your history from Google Analytics 4, and export raw events each night to your own storage, ready for BigQuery.',
    ]],
    ['date' => '2026-09-30', 'title' => 'Servers and environments do more', 'changes' => [
        'Add cron jobs, background processes, firewall rules, and Meilisearch, Typesense or Redis to a server in a click. Set up Reverb for a website in one step.',
        'Edit a website’s web server settings (with an automatic undo if they’re invalid), browse its files, and tail or search its logs.',
        'Put a domain behind Cloudflare’s CDN, block countries or addresses, and rate-limit it. The cache is cleared after each deploy.',
        'Storage buckets for your apps, previews that start with a copy of another website’s database, and trusted private networking between your servers.',
        'Autoscale an environment on CPU, put it into maintenance mode with a secret link for your team, and see which regions it runs in.',
        'Traces now show the deploy that served them. Go past a plan’s allowance and pay only for what you use, with an optional spend cap.',
    ]],
    ['date' => '2026-09-29', 'title' => 'Quicker to get around', 'changes' => [
        'Creating a server, your notifications and many more forms now open in place, without leaving the page.',
        'A footer with the platform’s status and quick links on every page, and breadcrumbs that step back through where you are.',
        'Billing shows each service on its own tab.',
    ]],
    ['date' => '2026-09-29', 'title' => 'Analytics: today, countries, page speed and revenue', 'changes' => [
        'A Today view with visits per hour, and a “Right now” panel that updates itself.',
        'See which countries visitors come from, how long visits last, and which Google searches brought them (connect Search Console).',
        'Count outbound links, file downloads, missing pages and page speed (Core Web Vitals) by adding one attribute to the snippet.',
        'Add revenue to your events to see what each goal and campaign earns.',
        'Share a site’s report with a link, with or without a password, and read reports through the API and SDKs.',
        'Reports stay fast on busy sites, and ?bp_ignore=1 leaves your own visits out.',
    ]],
    ['date' => '2026-09-29', 'title' => 'Deploy safely, page the right person, and grow', 'changes' => [
        'Deploy any branch, tag or commit, book a deploy for later, freeze an environment for the holidays, and let a jump in errors roll a deploy back.',
        'On-call schedules, text and phone alerts, incident post-mortems for your status page, and status updates in Slack or a webhook.',
        'Funnels, campaign links and results, and releases marked on your analytics chart.',
        'Linode and AWS Lightsail servers, PostgreSQL with point-in-time recovery, per-site PHP versions and budget alerts.',
        'Limit members to some projects, protect production, stream your audit log, require a second person for secrets, and SAML single sign-on.',
        'Pay yearly with two months free, and see what’s new right here.',
    ]],
    ['date' => '2026-09-29', 'title' => 'Set up faster, and follow things live', 'changes' => [
        'The setup guide now shows one step at a time, and you can connect a provider, add a website or add an Analytics site without leaving it.',
        'Explore a sample project with a month of made-up visits and a day of made-up errors before connecting anything real.',
        'Deploys stream their stages and log live, and incidents, monitors, servers, websites and backups update without a reload.',
        'A welcome email, and one reminder if a project’s setup stalls. Turn them off from any of them.',
    ]],
    ['date' => '2026-09-28', 'title' => 'Single sign-on, saved views and a help centre', 'changes' => [
        'Account → Security: require two-factor authentication, limit email domains and IP ranges, sign people out when idle, and sign in with your identity provider (OpenID Connect).',
        'Save the filters on the audit log, notifications and Monitoring’s events, issues and metrics, and export the audit log and notifications as CSV.',
        'Compare any two deploys: outcome, timing, the code changes and every setting that changed.',
        'Short guides for every part of BuildPusher in the new help centre, and a way to send us feedback from the app.',
        'Quick actions such as a new project, an invitation, an API token or a domain open in place instead of on a new page.',
        'Server setup is followed live, and new servers wait properly for their address.',
    ]],
    ['date' => '2026-09-27', 'title' => 'One platform', 'changes' => [
        'Deploy, Infrastructure, Monitoring and Analytics now live in one app, with one account, one bill and one set of permissions.',
    ]],
];
