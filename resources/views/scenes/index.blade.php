<x-layouts.core
    :title="__('Deploy with clarity')"
    :description="__('Provision infrastructure, ship Git releases, monitor health, and recover confidently from one focused control plane.')"
    :canonical="url('/')"
    :indexable="true"
    :livewire="false"
>
    @php
        $registrationOpen = app(\App\Services\RegistrationAccess::class)->allowsNewUser();
        $primaryUrl = $registrationOpen ? route('register') : route('access-request.create');
        $primaryLabel = $registrationOpen ? __('Start deploying') : __('Request access');
        $featureGroups = [
            'ship' => [
                'label' => __('Build & ship'), 'icon' => 'cloud-upload', 'title' => __('Make every deployment traceable'),
                'description' => __('From the first commit to a verified release, every step stays visible and recoverable.'),
                'features' => [
                    [__('Framework-ready runtimes'), __('Laravel, PHP, Node, Python, Docker, Next.js, Nuxt, Django, WordPress, and Statamic.')],
                    [__('Connected Git providers'), __('GitHub, GitLab, and Bitbucket repositories with signed webhook deployments.')],
                    [__('Safe release strategies'), __('Atomic, rolling, blue-green, and canary releases with retained versions.')],
                    [__('Preview environments'), __('Isolated pull-request previews with GitHub checks and durable status comments.')],
                    [__('Preflight and approvals'), __('Risk snapshots, approvals, locks, maintenance windows, cancellation, and release notes.')],
                    [__('Automatic recovery'), __('After an activated release fails, automatically return to the last retained successful version.')],
                ],
            ],
            'infrastructure' => [
                'label' => __('Infrastructure'), 'icon' => 'cloud', 'title' => __('Provision without the guesswork'),
                'description' => __('Create, connect, and evolve production infrastructure with the important state kept in view.'),
                'features' => [
                    [__('Cloud provisioning'), __('Provision or import servers, then follow each setup stage through to ready.')],
                    [__('Domains and TLS'), __('Manage primary domains, aliases, redirects, certificates, IPv4, and IPv6.')],
                    [__('Cloudflare automation'), __('Create DNS records and temporary domains without leaving the workspace.')],
                    [__('High availability'), __('Run weighted load-balancer pools with node probes, failover, and safe cleanup.')],
                    [__('Managed data services'), __('Create databases, Valkey, object storage, credentials, schemas, and connections.')],
                    [__('Cost visibility'), __('Review provider-catalog estimates, budgets, idle capacity, and right-sizing signals.')],
                ],
            ],
            'observe' => [
                'label' => __('Observe'), 'icon' => 'clock', 'title' => __('See health in context'),
                'description' => __('Understand what changed, what is slow, and what needs attention before it becomes guesswork.'),
                'features' => [
                    [__('Website health'), __('Scheduled checks, response timing, success rates, outage history, and recovery events.')],
                    [__('Server telemetry'), __('CPU, memory, disk, load, network, and process signals in compact trend charts.')],
                    [__('Threshold alerts'), __('Configurable warning levels, cooldowns, deduplication, and recovery notifications.')],
                    [__('Searchable logs'), __('Follow deployment and command output with retention, filtering, and safe downloads.')],
                    [__('Status communication'), __('Public service health, incident timelines, subscriptions, and safe API responses.')],
                    [__('Incident command centre'), __('Correlate operational history and retain root cause, remediation, and follow-up reviews.')],
                ],
            ],
            'automate' => [
                'label' => __('Automate'), 'icon' => 'terminal', 'title' => __('Act safely when it matters'),
                'description' => __('Turn repeatable operations into controlled workflows while preserving human accountability.'),
                'features' => [
                    [__('Scheduled tasks'), __('Encrypted commands, run history, timeouts, overlap protection, and failure alerts.')],
                    [__('Workers and processes'), __('Queue workers, restart policies, delays, scaling, hibernation, and resume controls.')],
                    [__('Command center'), __('Run, cancel, repeat, filter, and export owner-scoped server operations.')],
                    [__('API and webhooks'), __('Automate environments, deployments, rollback, logs, and infrastructure through OpenAPI.')],
                    [__('CLI and MCP'), __('Operate :app from scripts, terminals, or compatible AI tools.', ['app' => config('app.name')])],
                    [__('Reusable recipes'), __('Build private automation or install reviewable community recipes you can edit.')],
                ],
            ],
            'secure' => [
                'label' => __('Secure & govern'), 'icon' => 'cloud', 'title' => __('Govern access without slowing delivery'),
                'description' => __('Give teams the access they need, keep sensitive data protected, and retain an audit trail.'),
                'features' => [
                    [__('Encrypted secrets'), __('Versioned environment variables, provider tokens, keys, scripts, and command output.')],
                    [__('Organizations and roles'), __('Admin, operator, developer, auditor, billing, and viewer permissions.')],
                    [__('Enterprise sign-in'), __('OpenID Connect SSO with PKCE, mandatory two-factor authentication, and session controls.')],
                    [__('Access policies'), __('IP and CIDR restrictions, allowed email domains, and idle-session timeouts.')],
                    [__('Complete accountability'), __('Activity, sign-in, deployment, incident, and command histories with safe exports.')],
                    [__('Scoped ownership'), __('Resource authorization, signed callbacks, rate limits, and lockout prevention.')],
                ],
            ],
            'recover' => [
                'label' => __('Recover & reuse'), 'icon' => 'clock', 'title' => __('Recover with evidence, not instinct'),
                'description' => __('Keep the history and reusable building blocks needed to resolve the difficult day quickly.'),
                'features' => [
                    [__('Verified backups'), __('Create, inspect, restore, and retain database backups with clear outcomes.')],
                    [__('Release rollback'), __('Return to an exact known revision while keeping logs and deployment lineage.')],
                    [__('Database recovery'), __('Use expiring credentials, inspect schema health, and clone guarded environments.')],
                    [__('Incident history'), __('Connect failures, alerts, acknowledgement, and recovery to the affected resource.')],
                    [__('Recipe library'), __('Save working server setups and reuse moderated community automation safely.')],
                    [__('Platform diagnostics'), __('Review restricted service checks while the public sees a disclosure-safe status page.')],
                ],
            ],
        ];
        $tourAreas = [
            'overview' => ['label' => __('Overview'), 'title' => __('One calm operational overview'), 'description' => __('Current infrastructure, active work, and anything needing attention are visible before you open a resource.'), 'window' => __('Workspace'), 'metrics' => [['8', __('Websites')], ['3', __('Servers')], ['24', __('Deployments')]], 'events' => [[__('System health'), __('Operational')], [__('Latest deployment'), __('Succeeded')], [__('Open incidents'), '0']]],
            'provision' => ['label' => __('Provision'), 'title' => __('Infrastructure with visible progress'), 'description' => __('Track cloud creation, network assignment, software installation, recipes, and website placement as explicit stages.'), 'window' => __('Server provisioning'), 'metrics' => [['5/5', __('Stages')], ['1', __('Public IP')], ['3', __('Recipes')]], 'events' => [[__('Cloud server created'), __('Complete')], [__('Base software installed'), __('Complete')], [__('Ready for websites'), __('Complete')]]],
            'deploy' => ['label' => __('Deploy'), 'title' => __('Every release stays explainable'), 'description' => __('Follow revision, trigger, timing, heartbeat, output, and health verification from one deployment record.'), 'window' => __('Deployment #1841'), 'metrics' => [['a71c8ef', __('Revision')], ['42s', __('Duration')], [__('Webhook'), __('Trigger')]], 'events' => [[__('Fetching revision'), __('Complete')], [__('Activating release'), __('Complete')], [__('Health verification'), __('Passed')]]],
            'monitor' => ['label' => __('Monitor'), 'title' => __('Health checks that tell a story'), 'description' => __('Use scheduled and manual checks to understand response time, success rate, outages, and recoveries.'), 'window' => __('Website health'), 'metrics' => [['99%', __('Success')], ['184ms', __('Latest')], ['172ms', __('Median')]], 'events' => [[__('12:00 check'), __('Healthy')], [__('11:55 check'), __('Healthy')], [__('11:50 check'), __('Healthy')]]],
            'operate' => ['label' => __('Operate'), 'title' => __('Actions keep their accountability'), 'description' => __('Commands, activity, notifications, and incident history remain scoped to the owner and connected to their resources.'), 'window' => __('Command center'), 'metrics' => [['2', __('Active')], ['18', __('Succeeded')], ['1', __('Failed')]], 'events' => [['production-01', __('Running')], ['worker-01', __('Queued')], ['staging-01', __('Succeeded')]]],
            'reuse' => ['label' => __('Reuse'), 'title' => __('Repeat the setup that works'), 'description' => __('Keep private recipes or install reviewable community snapshots that remain editable in your own account.'), 'window' => __('Recipe library'), 'metrics' => [['12', __('Saved')], ['4', __('Installed')], ['1', __('Update')]], 'events' => [[__('Harden SSH access'), __('Installed')], [__('Install PHP runtime'), __('Current')], [__('Configure Redis'), __('Update ready')]]],
        ];
    @endphp

    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-emphasis focus:px-4 focus:py-3 focus:font-semibold focus:text-emphasis-ink focus:shadow-xl">{{ __('Skip to main content') }}</a>

    <header class="ui-public-header sticky top-0 z-40 border-b border-line bg-surface" x-data="{ navigationOpen: false }" @keydown.escape.window="navigationOpen = false">
        <div class="mx-auto flex h-16 max-w-7xl items-center gap-4 px-4 sm:px-6 lg:px-8">
            <a href="/" class="text-lg font-black uppercase tracking-tight text-ink sm:text-xl">{{ config('app.name') }}</a>
            <nav class="ml-auto hidden lg:block" aria-label="{{ __('Homepage navigation') }}">
                <ul class="flex items-center gap-7 text-sm font-semibold text-muted">
                    <li><a href="#features" class="transition hover:text-ink">{{ __('Capabilities') }}</a></li>
                    <li><a href="#product" class="transition hover:text-ink">{{ __('Product') }}</a></li>
                    <li><a href="#how-it-works" class="transition hover:text-ink">{{ __('How it works') }}</a></li>
                    <li><a href="{{ route('pricing') }}" class="transition hover:text-ink">{{ __('Pricing') }}</a></li>
                    <li><a href="{{ route('platform-status.show') }}" class="transition hover:text-ink">{{ __('Status') }}</a></li>
                    <li><a href="#questions" class="transition hover:text-ink">{{ __('Questions') }}</a></li>
                </ul>
            </nav>
            <div class="ml-auto flex items-center gap-2 lg:ml-5">
                <x-ui.button :href="route('login')" variant="secondary">{{ __('Sign in') }}</x-ui.button>
                @if ($registrationOpen)
                    <x-ui.button :href="route('register')" variant="primary" class="hidden sm:inline-flex">{{ __('Get started') }}</x-ui.button>
                @else
                    <x-ui.button :href="route('access-request.create')" variant="primary" class="hidden sm:inline-flex">{{ __('Request access') }}</x-ui.button>
                @endif
                <button id="navbarToggler" type="button" class="ui-btn ui-btn-primary lg:hidden" aria-controls="navbarCollapse" :aria-expanded="navigationOpen.toString()" aria-label="{{ __('Toggle navigation') }}" @click="navigationOpen = ! navigationOpen">
                    <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#menu"></use></svg>
                </button>
            </div>
        </div>
        <nav id="navbarCollapse" x-cloak x-show="navigationOpen" class="absolute inset-x-3 top-full rounded-xl border border-line bg-surface p-3 shadow-xl lg:hidden" aria-label="{{ __('Mobile homepage navigation') }}" @click="if ($event.target.closest('a')) navigationOpen = false">
            <ul class="grid gap-1 text-sm font-semibold text-muted">
                <li><a href="#features" class="block rounded-lg px-3 py-3 hover:bg-surface-muted hover:text-ink">{{ __('Capabilities') }}</a></li>
                <li><a href="#product" class="block rounded-lg px-3 py-3 hover:bg-surface-muted hover:text-ink">{{ __('Product') }}</a></li>
                <li><a href="#how-it-works" class="block rounded-lg px-3 py-3 hover:bg-surface-muted hover:text-ink">{{ __('How it works') }}</a></li>
                <li><a href="{{ route('pricing') }}" class="block rounded-lg px-3 py-3 hover:bg-surface-muted hover:text-ink">{{ __('Pricing') }}</a></li>
                <li><a href="{{ route('platform-status.show') }}" class="block rounded-lg px-3 py-3 hover:bg-surface-muted hover:text-ink">{{ __('Status') }}</a></li>
                <li><a href="#questions" class="block rounded-lg px-3 py-3 hover:bg-surface-muted hover:text-ink">{{ __('Questions') }}</a></li>
            </ul>
        </nav>
        <noscript>
            <nav class="border-t border-line px-4 py-3 lg:hidden" aria-label="{{ __('Homepage navigation without JavaScript') }}">
                <ul class="grid grid-cols-2 gap-2 text-sm font-semibold text-muted">
                    <li><a href="#features" class="block py-2">{{ __('Capabilities') }}</a></li><li><a href="#product" class="block py-2">{{ __('Product') }}</a></li><li><a href="#how-it-works" class="block py-2">{{ __('How it works') }}</a></li><li><a href="#questions" class="block py-2">{{ __('Questions') }}</a></li>
                </ul>
            </nav>
        </noscript>
    </header>

    <main id="main-content" tabindex="-1">
        <section data-landing-hero class="ui-landing-hero overflow-hidden bg-page">
            <div class="ui-landing-hero__layout mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:gap-12 sm:px-6 sm:py-16 lg:grid-cols-[0.9fr_1.1fr] lg:items-center lg:px-8">
                <div>
                    <p class="ui-badge ui-badge-primary">{{ __('Your infrastructure. One control plane.') }}</p>
                    <h1 class="mt-4 max-w-2xl text-4xl font-black leading-[1.05] tracking-tight text-ink sm:mt-6 sm:text-5xl lg:text-6xl">{{ __('Deploy with clarity. Recover with confidence.') }}</h1>
                    <p class="mt-4 max-w-xl text-lg leading-8 text-muted sm:mt-6">{{ __(':app brings provisioning, Git deployments, monitoring, commands, and operational history into one focused workspace.', ['app' => config('app.name')]) }}</p>
                    <div class="mt-6 flex flex-col gap-3 sm:mt-8 sm:flex-row">
                        <x-ui.button :href="$primaryUrl" variant="primary" class="w-full sm:w-auto">{{ $primaryLabel }}</x-ui.button>
                        <x-ui.button href="#product" variant="secondary" class="w-full sm:w-auto">{{ __('Explore the product') }}</x-ui.button>
                    </div>
                    <div class="mt-6 flex flex-wrap gap-x-6 gap-y-3 text-sm font-medium text-muted sm:mt-8">
                        @foreach ([__('Encrypted secrets'), __('Tracked releases'), __('Owner-scoped access')] as $promise)
                            <span class="flex items-center gap-2"><span class="flex h-5 w-5 items-center justify-center rounded-full bg-emphasis text-xs text-emphasis-ink">✓</span>{{ $promise }}</span>
                        @endforeach
                    </div>
                </div>
                <div data-illustrative-preview class="ui-landing-preview overflow-hidden rounded-2xl border border-line bg-surface shadow-2xl" aria-label="{{ __('Illustrative workspace preview') }}">
                    <div class="flex items-center justify-between border-b border-line px-5 py-4"><div class="flex gap-1.5" aria-hidden="true"><span class="h-2.5 w-2.5 rounded-full bg-emphasis"></span><span class="h-2.5 w-2.5 rounded-full bg-surface-muted"></span><span class="h-2.5 w-2.5 rounded-full bg-emphasis"></span></div><span class="text-xs font-bold uppercase tracking-widest text-muted">{{ __('Illustrative workspace') }}</span></div>
                    <div class="grid gap-4 bg-surface-muted p-4 sm:grid-cols-[1.35fr_.65fr] sm:p-5">
                        <div class="rounded-xl border border-line bg-surface p-5">
                            <div class="flex items-center justify-between gap-3"><p class="font-bold text-ink">storefront</p><span class="rounded-full bg-emphasis px-2.5 py-1 text-[0.6875rem] font-bold uppercase text-emphasis-ink">{{ __('Deploying') }}</span></div>
                            <p class="mt-1 font-mono text-xs text-muted">main · a71c8ef</p><div class="mt-5 h-2 overflow-hidden rounded-full bg-surface-muted"><div class="h-full w-4/5 rounded-full bg-emphasis"></div></div>
                            <div class="mt-5 space-y-3 text-sm">@foreach ([[__('Revision fetched'), __('Complete')], [__('Dependencies installed'), __('Complete')], [__('Health verification'), __('Running')]] as [$event, $state])<div class="flex items-center gap-3"><span class="h-2 w-2 rounded-full bg-emphasis"></span><span class="text-ink">{{ $event }}</span><span class="ml-auto text-xs font-semibold text-muted">{{ $state }}</span></div>@endforeach</div>
                        </div>
                        <div class="grid grid-cols-3 gap-3 sm:grid-cols-1">@foreach ([['3', __('Servers')], ['8', __('Websites')], ['0', __('Incidents')]] as [$value, $label])<div class="rounded-xl border border-line bg-surface p-4"><p class="text-2xl font-black text-ink">{{ $value }}</p><p class="mt-1 text-xs text-muted">{{ $label }}</p></div>@endforeach</div>
                    </div>
                    <div class="flex items-center gap-3 border-t border-line px-5 py-4 text-xs text-muted"><span class="h-2 w-2 rounded-full bg-emphasis"></span><span>{{ __('Example data · not live telemetry') }}</span><span class="ml-auto font-mono">{{ __('Demo') }}</span></div>
                </div>
            </div>
        </section>

        <section class="ui-provider-strip border-y border-line bg-surface-muted" aria-labelledby="providers-heading">
            <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-6 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
                <h2 id="providers-heading" class="text-xs font-bold uppercase tracking-widest text-muted">{{ __('Works with the providers you already use') }}</h2>
                <ul class="grid grid-cols-2 gap-2 sm:flex" aria-label="{{ __('Supported providers') }}">@foreach ([['digital-ocean', 'DigitalOcean'], ['github', 'GitHub'], ['gitlab', 'GitLab'], ['bitbucket', 'Bitbucket']] as [$icon, $name])<li class="flex items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2 text-sm font-bold text-ink"><svg class="h-4 w-4 fill-current" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#{{ $icon }}"></use></svg>{{ $name }}</li>@endforeach</ul>
            </div>
        </section>

        <section id="features" class="scroll-mt-20 bg-page py-14" x-data="{ activeFeature: 'ship' }">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-2xl"><p class="ui-eyebrow">{{ __('The complete platform') }}</p><h2 class="mt-3 text-3xl font-black tracking-tight text-ink sm:text-4xl">{{ __('The release lifecycle, without the tool sprawl.') }}</h2></div>
                    <p class="max-w-xl leading-7 text-muted">{{ __('Explore every part of :app in one compact view. Choose a category to see what is included.', ['app' => config('app.name')]) }}</p>
                </div>

                <div class="mt-8 overflow-hidden rounded-2xl border border-line bg-surface-muted shadow-xl">
                    <div class="overflow-x-auto border-b border-line bg-surface p-2 lg:hidden" aria-label="{{ __('Feature categories') }}">
                        <div class="flex w-max gap-2">
                            @foreach ($featureGroups as $key => $group)
                                <button type="button" class="whitespace-nowrap rounded-lg px-4 py-2.5 text-sm font-bold transition" :class="activeFeature === '{{ $key }}' ? 'bg-emphasis text-emphasis-ink shadow-sm' : 'text-muted hover:bg-surface-muted hover:text-ink'" :aria-pressed="(activeFeature === '{{ $key }}').toString()" @click="activeFeature = '{{ $key }}'">{{ $group['label'] }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid lg:grid-cols-[15rem_1fr]">
                        <div class="hidden border-r border-line bg-surface p-3 lg:block" aria-label="{{ __('Feature categories') }}">
                            @foreach ($featureGroups as $key => $group)
                                <button type="button" class="mb-1 flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold transition last:mb-0" :class="activeFeature === '{{ $key }}' ? 'bg-emphasis text-emphasis-ink shadow-sm' : 'text-muted hover:bg-surface-muted hover:text-ink'" :aria-pressed="(activeFeature === '{{ $key }}').toString()" @click="activeFeature = '{{ $key }}'">
                                    <svg class="h-5 w-5 shrink-0 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#{{ $group['icon'] }}"></use></svg>
                                    <span>{{ $group['label'] }}</span><span class="ml-auto text-xs opacity-70">{{ count($group['features']) }}</span>
                                </button>
                            @endforeach
                        </div>

                        <div class="min-w-0 p-5 sm:p-7">
                            @foreach ($featureGroups as $key => $group)
                                <article x-show="activeFeature === '{{ $key }}'" @if (! $loop->first) x-cloak @endif>
                                    <div class="flex items-start gap-4">
                                        <div class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emphasis text-emphasis-ink sm:flex lg:hidden"><svg class="h-6 w-6 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#{{ $group['icon'] }}"></use></svg></div>
                                        <div><p class="ui-eyebrow">{{ $group['label'] }}</p><h3 class="mt-2 text-2xl font-black text-ink">{{ $group['title'] }}</h3><p class="mt-2 max-w-3xl text-sm leading-6 text-muted sm:text-base">{{ $group['description'] }}</p></div>
                                    </div>
                                    <ul class="mt-6 grid gap-x-7 gap-y-4 sm:grid-cols-2 xl:grid-cols-3">
                                        @foreach ($group['features'] as [$title, $description])
                                            <li class="flex gap-3"><span class="mt-1 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emphasis text-[0.6875rem] font-black text-emphasis-ink">✓</span><div><h4 class="text-sm font-bold text-ink">{{ $title }}</h4><p class="mt-1 text-xs leading-5 text-muted">{{ $description }}</p></div></li>
                                        @endforeach
                                    </ul>
                                </article>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-line bg-surface px-5 py-3 text-xs font-semibold text-muted">
                        <span><strong class="text-ink">36</strong> {{ __('capabilities') }}</span><span><strong class="text-ink">6</strong> {{ __('focused groups') }}</span><span class="sm:ml-auto">{{ __('Illustrative previews · no live workspace data.') }}</span>
                    </div>
                </div>
            </div>
        </section>

        <section
            id="product"
            class="scroll-mt-20 border-y border-line bg-surface-muted py-16"
            x-data="{
                activeArea: 'overview',
                areas: {{ Illuminate\Support\Js::from(array_keys($tourAreas)) }},
                focusArea(index) {
                    this.activeArea = this.areas[(index + this.areas.length) % this.areas.length];
                    this.$nextTick(() => document.getElementById(`area-tab-${this.activeArea}`).focus());
                },
                moveArea(offset) {
                    this.focusArea(this.areas.indexOf(this.activeArea) + offset);
                },
            }"
        >
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between"><div class="max-w-2xl"><p class="ui-eyebrow">{{ __('Inside the product') }}</p><h2 class="mt-3 text-3xl font-black tracking-tight text-ink sm:text-4xl">{{ __('One workspace, every operational stage.') }}</h2></div><p class="max-w-lg leading-7 text-muted">{{ __('Explore representative workflows with illustrative data. Use them to see where each operation lives.') }}</p></div>
                <div class="mt-8 overflow-x-auto pb-2" role="tablist" aria-label="{{ __('Product areas') }}"><div class="flex w-max gap-2 rounded-xl border border-line bg-surface p-2">@foreach ($tourAreas as $key => $area)<button id="area-tab-{{ $key }}" type="button" role="tab" class="whitespace-nowrap rounded-lg px-4 py-2.5 text-sm font-bold transition focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2" :class="activeArea === '{{ $key }}' ? 'bg-emphasis text-emphasis-ink shadow-sm' : 'text-muted hover:bg-surface-muted hover:text-ink'" :aria-selected="(activeArea === '{{ $key }}').toString()" :tabindex="activeArea === '{{ $key }}' ? '0' : '-1'" aria-controls="area-panel-{{ $key }}" @click="activeArea = '{{ $key }}'" @keydown.right.prevent="moveArea(1)" @keydown.left.prevent="moveArea(-1)" @keydown.home.prevent="focusArea(0)" @keydown.end.prevent="focusArea(areas.length - 1)">{{ $area['label'] }}</button>@endforeach</div></div>
                <div class="mt-5 rounded-2xl border border-line bg-surface p-5 shadow-xl sm:p-7">
                    @foreach ($tourAreas as $key => $area)
                        <article id="area-panel-{{ $key }}" role="tabpanel" aria-labelledby="area-tab-{{ $key }}" x-show="activeArea === '{{ $key }}'" @if (! $loop->first) x-cloak @endif class="grid items-center gap-8 lg:grid-cols-[.65fr_1.35fr]">
                            <div><p class="ui-eyebrow">{{ $area['label'] }}</p><h3 class="mt-3 text-2xl font-black text-ink sm:text-3xl">{{ $area['title'] }}</h3><p class="mt-4 leading-7 text-muted">{{ $area['description'] }}</p></div>
                            <div class="overflow-hidden rounded-xl border border-line bg-surface-muted" aria-hidden="true"><div class="flex items-center justify-between border-b border-line bg-surface px-4 py-3"><span class="text-xs font-bold text-muted">{{ $area['window'] }}</span><span class="rounded-full bg-emphasis px-2 py-1 text-[0.625rem] font-bold uppercase text-emphasis-ink">{{ __('Example') }}</span></div><div class="grid grid-cols-3 gap-2 p-4">@foreach ($area['metrics'] as [$value, $label])<div class="rounded-lg border border-line bg-surface p-3"><p class="truncate text-base font-black text-ink sm:text-xl">{{ $value }}</p><p class="mt-1 truncate text-[0.625rem] uppercase text-muted">{{ $label }}</p></div>@endforeach</div><div class="mx-4 mb-4 overflow-hidden rounded-lg border border-line bg-surface">@foreach ($area['events'] as [$event, $state])<div class="flex items-center gap-3 border-b border-line px-3 py-3 text-sm last:border-0"><span class="h-2 w-2 shrink-0 rounded-full bg-emphasis"></span><span class="min-w-0 truncate text-ink">{{ $event }}</span><span class="ml-auto shrink-0 text-xs font-semibold text-muted">{{ $state }}</span></div>@endforeach</div></div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="how-it-works" class="scroll-mt-20 bg-page py-16">
            <div class="mx-auto grid max-w-7xl gap-10 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
                <div><p class="ui-eyebrow">{{ __('Three everyday workflows') }}</p><h2 class="mt-3 text-3xl font-black tracking-tight text-ink">{{ __('Connect. Provision. Deploy.') }}</h2><ol class="mt-7 space-y-3">@foreach ([[__('Launch with confidence'), __('Connect providers, provision infrastructure, run preflight, and verify the first release.')], [__('Recover without guesswork'), __('Correlate the incident, inspect retained evidence, and roll back to an exact release.')], [__('Scale with control'), __('Set capacity, schedules, health thresholds, cost budgets, and high-availability routing.')]] as [$title, $description])<li class="flex gap-4 rounded-xl border border-line bg-surface-muted p-4"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emphasis text-xs font-black text-emphasis-ink">{{ $loop->iteration }}</span><div><h3 class="font-bold text-ink">{{ $title }}</h3><p class="mt-1 text-sm text-muted">{{ $description }}</p></div></li>@endforeach</ol></div>
                <div class="rounded-2xl bg-emphasis p-7 text-emphasis-ink sm:p-8"><p class="ui-eyebrow">{{ __('Guardrails included') }}</p><h2 class="mt-3 text-3xl font-black text-emphasis-ink">{{ __('Designed for the difficult day.') }}</h2><p class="mt-4 leading-7 text-emphasis-muted">{{ __('Failures keep their logs, revision context, and recovery actions. Sensitive values stay encrypted and operations stay owner-scoped.') }}</p><ul class="mt-7 grid gap-3 sm:grid-cols-2">@foreach ([__('Encrypted credentials'), __('Signed callbacks'), __('Bounded history'), __('Verified backups'), __('Health thresholds'), __('Exact-revision recovery')] as $guardrail)<li class="flex items-center gap-2 text-sm font-semibold text-emphasis-ink"><span class="text-emphasis-ink">✓</span>{{ $guardrail }}</li>@endforeach</ul></div>
            </div>
        </section>

        <section id="questions" class="scroll-mt-20 border-t border-line bg-surface-muted py-16">
            <div class="mx-auto grid max-w-6xl gap-8 px-4 sm:px-6 lg:grid-cols-[.7fr_1.3fr] lg:px-8"><div><p class="ui-eyebrow">{{ __('Good to know') }}</p><h2 class="mt-3 text-3xl font-black text-ink">{{ __('Straight answers.') }}</h2></div><div class="space-y-3">@foreach ([[__('Who is :app for?', ['app' => config('app.name')]), __('Development teams and operators who want a focused control plane while keeping applications in their own cloud accounts.')], [__('Where does my application run?'), __('On infrastructure in the provider account you connect. :app coordinates the operational workflow.', ['app' => config('app.name')])], [__('Can I recover a previous release?'), __('Yes. Recorded revisions can be redeployed with lineage and logs retained, provided the target is ready.')], [__('How are sensitive values handled?'), __('Provider tokens, environments, scripts, command text, and retained output are encrypted at rest and owner-scoped.')], [__('What does :app not replace?', ['app' => config('app.name')]), __('Your cloud provider, source host, application architecture, and independent external monitoring remain separate. Provider invoices remain authoritative for cost.')]] as [$question, $answer])<details class="group rounded-xl border border-line bg-surface p-5"><summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-bold text-ink">{{ $question }}<span class="text-xl text-ink transition group-open:rotate-45" aria-hidden="true">+</span></summary><p class="mt-3 leading-7 text-muted">{{ $answer }}</p></details>@endforeach</div></div>
        </section>

        <section class="ui-public-cta border-t border-line bg-surface py-14"><div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8"><div><h2 class="text-3xl font-black tracking-tight text-ink">{{ __('Make the next deployment the clear one.') }}</h2><p class="mt-2 text-muted">{{ __('Bring infrastructure, releases, and recovery into one workspace.') }}</p></div><a href="{{ $primaryUrl }}" class="ui-btn ui-btn-primary" >{{ $primaryLabel }}</a></div></section>
    </main>

    <footer class="border-t border-line bg-surface-muted px-4 py-8"><div class="mx-auto flex max-w-7xl flex-col gap-5 sm:px-2 md:flex-row md:items-center md:justify-between lg:px-4"><div><p class="font-black uppercase tracking-tight text-ink">{{ config('app.name') }}</p><p class="mt-1 text-sm text-muted">{{ __('Your infrastructure. One focused control plane.') }}</p></div><nav aria-label="{{ __('Footer navigation') }}" class="flex flex-wrap gap-5 text-sm font-medium text-muted"><a href="#features" class="hover:text-ink">{{ __('Capabilities') }}</a><a href="#product" class="hover:text-ink">{{ __('Product') }}</a><a href="#how-it-works" class="hover:text-ink">{{ __('How it works') }}</a><a href="{{ route('platform-status.show') }}" class="hover:text-ink">{{ __('Status') }}</a><a href="{{ route('privacy') }}" class="hover:text-ink">{{ __('Privacy') }}</a><a href="{{ route('terms') }}" class="hover:text-ink">{{ __('Terms') }}</a><a href="{{ route('login') }}" class="hover:text-ink">{{ __('Sign in') }}</a></nav></div></footer>
</x-layouts.core>
