<x-signal.layouts.core
    :title="__('Deployer guide') . ' · Buildpusher'"
    :description="__('Move from an empty workspace to a verified Deployer release with a safe operational workflow.')"
    :canonical="route('core.help.deployer')"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.blocks.public-navigation active-product="deployer" />

    <main id="main-content" tabindex="-1" class="mx-auto max-w-screen-2xl px-5 py-10 text-ink sm:px-8 sm:py-14">
        <div class="mx-auto max-w-6xl">
            <div class="flex flex-wrap justify-end gap-2">
                <x-signal.ui.button href="#troubleshooting" variant="secondary">{{ __('Troubleshooting') }}</x-signal.ui.button>
                <x-signal.ui.button :href="$apiUrl" variant="secondary">{{ __('API reference') }}</x-signal.ui.button>
                <x-signal.ui.button :href="route('core.help')" variant="quiet">{{ __('All help') }}</x-signal.ui.button>
            </div>
            <header class="mt-8 max-w-3xl">
                <p class="ui-eyebrow">{{ __('Deployer · Getting started') }}</p>
                <h1 class="mt-2 text-4xl font-extrabold">{{ __('From empty workspace to verified release') }}</h1>
                <p class="mt-3 text-base leading-7 text-muted">{{ __('Follow the shortest safe path first. Add automation, scaling, and team controls after one manual release and recovery drill succeed.') }}</p>
            </header>

            <x-signal.ui.card as="details" class="group mt-8 overflow-hidden" id="guide-contents">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-3 font-bold text-ink [&::-webkit-details-marker]:hidden">
                    <span>{{ __('On this page') }}</span>
                    <span class="flex items-center gap-2 text-sm font-normal text-muted"><span>{{ __('6 guide sections') }}</span><span class="text-lg leading-none transition group-open:rotate-45" aria-hidden="true">+</span></span>
                </summary>
                <nav class="grid gap-2 border-t border-line p-4 sm:grid-cols-2 lg:grid-cols-3" aria-label="{{ __('Guide sections') }}">
                    @foreach ([['quick-start', __('Quick start')], ['first-deploy', __('First deployment')], ['operations', __('Daily operations')], ['recovery', __('Recovery')], ['security', __('Release checklist')], ['troubleshooting', __('Troubleshooting')]] as [$anchor, $label])
                        <x-signal.ui.card as="a" tone="interactive" class="block px-4 py-3 font-bold text-ink" href="#{{ $anchor }}">{{ $label }}</x-signal.ui.card>
                    @endforeach
                </nav>
            </x-signal.ui.card>

            <x-signal.ui.card as="section" class="mt-6 scroll-mt-6 p-6" id="quick-start">
                <div class="grid gap-6 lg:grid-cols-[.7fr_1.3fr]">
                    <div>
                        <p class="ui-eyebrow">{{ __('Ten-minute orientation') }}</p>
                        <h2 class="mt-2 text-2xl font-extrabold">{{ __('Know where things live') }}</h2>
                        <p class="mt-3 leading-7 text-muted">{{ __('Applications group environments. Sites own domains and health checks. Repositories produce releases. Observability and Backups retain operational evidence.') }}</p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ([[__('⌘K / Ctrl K'), __('Search resources and open common actions.')], [__('Applications'), __('Configure runtime, secrets, processes, and release controls.')], [__('Observability'), __('Review metrics, logs, incidents, status pages, and alerts.')], [__('Feedback'), __('Privately report a bug or confusing workflow from your workspace.')]] as [$title, $text])
                            <x-signal.ui.card tone="muted" class="p-4"><h3 class="font-extrabold">{{ $title }}</h3><p class="mt-1 text-sm leading-6 text-muted">{{ $text }}</p></x-signal.ui.card>
                        @endforeach
                    </div>
                </div>
            </x-signal.ui.card>

            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <x-signal.ui.card as="section" class="scroll-mt-6 p-6" id="first-deploy">
                    <p class="ui-eyebrow">{{ __('Start here') }}</p><h2 class="mt-1 text-xl font-extrabold">{{ __('First deployment') }}</h2>
                    <ol class="mt-4 list-decimal space-y-3 pl-5 leading-6 text-muted">
                        <li>{{ __('Connect DigitalOcean, Hetzner, or Vultr and use Test connection.') }}</li>
                        <li>{{ __('Provision Ubuntu, or import an existing Ubuntu server using an SSH key.') }}</li>
                        <li>{{ __('Create a site, point DNS to its public IP, and enable a health path that returns 2xx.') }}</li>
                        <li>{{ __('Connect GitHub, GitLab, or Bitbucket; choose the exact repository and branch.') }}</li>
                        <li>{{ __('Configure the runtime and secrets, then review the deployment preflight snapshot.') }}</li>
                        <li>{{ __('Deploy manually, inspect every stage, and confirm the post-release health result.') }}</li>
                    </ol>
                </x-signal.ui.card>

                <x-signal.ui.card as="section" class="scroll-mt-6 p-6" id="operations">
                    <p class="ui-eyebrow">{{ __('Repeat safely') }}</p><h2 class="mt-1 text-xl font-extrabold">{{ __('Daily operations') }}</h2>
                    <ul class="mt-4 list-disc space-y-3 pl-5 leading-6 text-muted">
                        <li>{{ __('Use approval, release locks, and maintenance windows for production changes.') }}</li>
                        <li>{{ __('Watch the deployment timeline, searchable logs, health history, and server metrics together.') }}</li>
                        <li>{{ __('Set alert thresholds and send failures and recoveries to an external destination.') }}</li>
                        <li>{{ __('Use buildpusher.yaml, scoped API tokens, the CLI, or MCP only after the manual path works.') }}</li>
                        <li>{{ __('Review cost estimates and hibernate truly idle environments; treat the provider invoice as authoritative.') }}</li>
                    </ul>
                </x-signal.ui.card>

                <x-signal.ui.card as="section" class="scroll-mt-6 p-6" id="recovery">
                    <p class="ui-eyebrow">{{ __('Practice before failure') }}</p><h2 class="mt-1 text-xl font-extrabold">{{ __('Recovery drill') }}</h2>
                    <ol class="mt-4 list-decimal space-y-3 pl-5 leading-6 text-muted">
                        <li>{{ __('Configure an offsite S3-compatible destination and a bounded retention schedule.') }}</li>
                        <li>{{ __('Run a backup and wait for a verified snapshot identifier and size.') }}</li>
                        <li>{{ __('Restore into a disposable environment; confirm the safety snapshot and health verification complete.') }}</li>
                        <li>{{ __('Deploy a known release, then perform an exact-release rollback.') }}</li>
                        <li>{{ __('Record root cause, remediation, follow-up owner, recovery point, and observed restore time.') }}</li>
                    </ol>
                    <x-signal.ui.alert class="mt-4" tone="info">{{ __('Automatic rollback protects an activated release, but it does not replace tested offsite recovery.') }}</x-signal.ui.alert>
                </x-signal.ui.card>

                <x-signal.ui.card as="section" class="scroll-mt-6 p-6" id="security">
                    <p class="ui-eyebrow">{{ __('Before inviting users') }}</p><h2 class="mt-1 text-xl font-extrabold">{{ __('Release and security checklist') }}</h2>
                    <ul class="mt-4 space-y-3 leading-6 text-muted">
                        @foreach ([__('APP_DEBUG is off and HTTPS/HSTS are verified.'), __('SMTP sender, password reset, verification, invitation, and alert emails are tested.'), __('Independent uptime and heartbeat monitors run outside this server.'), __('Every administrator uses two-factor authentication and stores recovery codes offline.'), __('Members have the smallest role required; old sessions and API tokens are revoked.'), __('Secret rotation dates, backup retention, log retention, and rate limits are reviewed.'), __('A real provider provision, deploy, rollback, backup, and restore drill has passed.'), __('Stripe remains disabled until product and price identifiers are approved.')] as $item)
                            <li class="flex gap-3"><span class="text-ink" aria-hidden="true">□</span><span>{{ $item }}</span></li>
                        @endforeach
                    </ul>
                </x-signal.ui.card>
            </div>

            <x-signal.ui.card as="section" class="mt-6 scroll-mt-6 p-6" id="troubleshooting">
                <p class="ui-eyebrow">{{ __('Decision guide') }}</p><h2 class="mt-1 text-2xl font-extrabold">{{ __('Troubleshooting') }}</h2>
                <p class="mt-2 text-sm leading-6 text-muted">{{ __('Start with the visible resource state and retained evidence. Do not rerun repeatedly before understanding whether remote work is still active.') }}</p>
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    @foreach ([[__('Provider connection failed'), __('Connection history and credential scope'), __('Test once; rotate only if the provider rejects the credential.')], [__('Server provisioning stopped'), __('Provisioning stage, streamed log, cloud console'), __('Retry only the eligible failed phase; do not create a duplicate server.')], [__('Deployment is waiting'), __('Approval, release lock, maintenance window, active site build'), __('Resolve the explicit guardrail or cancel the queued request.')], [__('Deployment failed before activation'), __('Preflight snapshot and deployment log'), __('Correct configuration, then redeploy the exact revision.')], [__('Health failed after activation'), __('Health path, runtime log, latest server metrics'), __('Follow automatic recovery, or roll back to a retained successful release.')], [__('Site is unreachable'), __('DNS records, certificate inspection, load-balancer nodes'), __('Fix the failing layer; avoid changing DNS and application state together.')], [__('Backup or restore failed'), __('Destination verification and retained job error'), __('Preserve the current site, correct credentials or capacity, then retry once.')], [__('Unexpected 500 response'), __('The safe incident reference in the response'), __('Give the reference to an administrator; never paste secrets into feedback.')]] as [$symptom, $check, $action])
                        <x-signal.ui.card as="article" tone="muted" class="p-4">
                            <h3 class="font-bold text-ink">{{ $symptom }}</h3>
                            <dl class="mt-3 space-y-3 text-sm"><div><dt class="text-xs font-bold uppercase tracking-wide text-muted">{{ __('Check first') }}</dt><dd class="mt-1 leading-6 text-muted">{{ $check }}</dd></div><div><dt class="text-xs font-bold uppercase tracking-wide text-muted">{{ __('Safe next action') }}</dt><dd class="mt-1 leading-6 text-muted">{{ $action }}</dd></div></dl>
                        </x-signal.ui.card>
                    @endforeach
                </div>
                <div class="mt-5 flex flex-wrap gap-3">
                    <x-signal.ui.button :href="route('core.status')" variant="secondary">{{ __('Public platform status') }}</x-signal.ui.button>
                    <x-signal.ui.button :href="route('core.home')" variant="primary">{{ __('Open your workspace') }}</x-signal.ui.button>
                </div>
            </x-signal.ui.card>
        </div>
    </main>
</x-signal.layouts.core>
