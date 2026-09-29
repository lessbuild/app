@php($hero = config('marketing.hero'))

@push('head')
    {{-- Tells search engines what the product is and that it has a free tier. --}}
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'Organization', 'name' => config('app.name'), 'url' => route('home'), 'logo' => asset('images/og/default.png')],
            ['@type' => 'SoftwareApplication', 'name' => config('app.name'), 'url' => route('home'), 'applicationCategory' => 'DeveloperApplication', 'operatingSystem' => 'Web',
                'description' => config('marketing.summary'), 'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD', 'description' => 'Free tier for every service']],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endpush

<x-signal.layouts.public :title="config('app.name')" :description="__(config('marketing.summary'))" :canonical="route('home')">
    {{-- Hero: the promise on the left, a glimpse of the dashboard on the right. --}}
    <section class="relative overflow-hidden border-b border-line bg-surface">
        <div class="surface-grid absolute inset-0 opacity-50" aria-hidden="true"></div>
        <div class="relative mx-auto grid max-w-6xl items-center gap-12 px-5 py-12 sm:px-8 sm:py-24 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16 lg:py-28">
            <div>
                <p class="ui-badge ui-badge-primary"><x-signal.ui.icon name="layers" class="h-3.5 w-3.5" /> {{ __($hero['badge']) }}</p>
                <h1 class="mt-6 max-w-2xl text-4xl font-extrabold tracking-[-0.05em] text-ink sm:text-6xl sm:leading-[1.04]">{{ __($hero['headline']) }}<br><span class="text-primary">{{ __($hero['accent']) }}</span></h1>
                <p class="mt-6 max-w-xl text-base leading-7 text-muted sm:text-lg">{{ __(config('marketing.summary')) }}</p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('register') }}" class="ui-btn ui-btn-primary ui-btn-lg">{{ __('Start free') }} <x-signal.ui.icon name="arrow-right" class="h-4 w-4" /></a>
                    <a href="#services" class="ui-btn ui-btn-secondary ui-btn-lg">{{ __('Explore the services') }} <x-signal.ui.icon name="arrow-down" class="h-4 w-4" /></a>
                </div>
                <ul class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-3 text-xs font-semibold text-muted">
                    @foreach ($hero['points'] as $point)
                        <li class="inline-flex items-center gap-2"><x-signal.ui.icon name="check" class="h-4 w-4 text-success" /> {{ __($point) }}</li>
                    @endforeach
                </ul>
            </div>

            {{-- A glimpse of the dashboard; phones go straight on to the services. --}}
            <div class="relative mx-auto hidden w-full max-w-2xl sm:block" aria-hidden="true">
                <div class="absolute -inset-8 rounded-full bg-primary/10 blur-3xl"></div>
                <div class="ui-panel relative overflow-hidden p-3 sm:p-4">
                    <div class="flex items-center justify-between border-b border-line px-2 pb-3">
                        <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-danger"></span><span class="h-2.5 w-2.5 rounded-full bg-warning"></span><span class="h-2.5 w-2.5 rounded-full bg-success"></span></div>
                        <span class="rounded-full bg-surface-muted px-3 py-1 text-[10px] font-bold text-muted">{{ strtolower(config('app.name')) }} / storefront / production</span>
                    </div>
                    <div class="grid gap-3 p-2 pt-4 sm:grid-cols-2">
                        <div class="rounded-card border border-line bg-surface-muted p-4">
                            <div class="flex items-center justify-between"><span class="text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ __('Deploy') }}</span><span class="grid h-8 w-8 place-items-center rounded-card bg-surface text-primary"><x-signal.ui.icon name="cloud-upload" class="h-4 w-4" /></span></div>
                            <p class="mt-6 text-lg font-extrabold text-ink">{{ __('Release #1841') }}</p>
                            <p class="mt-1 text-xs text-muted">main · a71c8ef</p>
                            <div class="mt-5 space-y-3">
                                @foreach ([['Approval', 'Passed'], ['Activate release', 'Complete'], ['Verify health', 'Passed']] as [$label, $state])
                                    <div class="flex items-center justify-between gap-3 text-xs"><span class="flex min-w-0 items-center gap-2 text-muted"><span class="h-1.5 w-1.5 shrink-0 rounded-full bg-success"></span><span class="truncate">{{ __($label) }}</span></span><span class="font-bold text-ink">{{ __($state) }}</span></div>
                                @endforeach
                            </div>
                        </div>
                        <div class="rounded-card border border-line bg-surface p-4">
                            <div class="flex items-center justify-between"><span class="text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ __('Monitoring') }}</span><span class="grid h-8 w-8 place-items-center rounded-card bg-primary-soft text-primary"><x-signal.ui.icon name="pulse" class="h-4 w-4" /></span></div>
                            <p class="mt-6 text-lg font-extrabold text-ink">{{ __('Systems healthy') }}</p>
                            <p class="mt-1 text-xs text-muted">{{ __('Latest check · 184ms') }}</p>
                            <div class="mt-5 grid grid-cols-3 gap-2">
                                @foreach ([['API', '99.99%'], ['Worker', 'Up'], ['TLS', 'Valid']] as [$label, $value])
                                    <div class="rounded-card border border-line bg-surface-muted p-2.5"><p class="text-[10px] font-bold uppercase tracking-[0.12em] text-subtle">{{ __($label) }}</p><p class="mt-2 text-sm font-extrabold text-ink">{{ __($value) }}</p></div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center justify-between gap-2 border-t border-line px-2 pt-3 text-[11px] text-muted"><span>{{ __('Release a71c8ef marked on Monitoring and Analytics.') }}</span><span class="inline-flex items-center gap-1.5 font-bold text-success"><x-signal.ui.icon name="check-circle" class="h-3.5 w-3.5" /> {{ __('Operational') }}</span></div>
                </div>
            </div>
        </div>
    </section>

    {{-- The product suite, as Signal lays it out: a card per service, then the explorer. Phones get the explorer only. --}}
    <section id="services" class="scroll-mt-20 bg-surface-muted/40" aria-labelledby="services-heading">
        <div class="mx-auto max-w-6xl px-5 py-12 sm:px-8 sm:py-24">
            <div class="grid gap-4 lg:grid-cols-[1fr_0.9fr] lg:items-end">
                <div>
                    <p class="ui-eyebrow">{{ __(':app services', ['app' => config('app.name')]) }}</p>
                    <h2 id="services-heading" class="mt-3 text-3xl font-extrabold tracking-[-0.04em] text-ink sm:text-4xl">{{ __('Choose the tool for the work in front of you.') }}</h2>
                </div>
                <p class="text-base leading-7 text-muted">{{ __('Turn on the services each project needs. They share your team, your alerts and your bill.') }}</p>
            </div>
            <div class="mt-9 hidden gap-4 sm:grid sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($services as $service)
                    <x-signal.blocks.service-card :service="$service" :copy="config('marketing.services.'.$service->key())" />
                @endforeach
            </div>
            <x-signal.blocks.service-explorer :services="$services" class="mt-8 sm:mt-14" />
        </div>
    </section>

    {{-- How the pieces fit. --}}
    <section id="workflow" class="border-y border-line bg-surface-muted/60">
        <div class="mx-auto grid max-w-6xl gap-8 px-5 py-12 sm:gap-12 sm:px-8 sm:py-24 lg:grid-cols-[0.8fr_1.2fr] lg:items-start">
            <div>
                <p class="ui-eyebrow">{{ __('How the pieces fit') }}</p>
                <h2 class="mt-3 text-3xl font-extrabold tracking-[-0.04em] text-ink sm:text-4xl">{{ __('A clear path from commit to confidence.') }}</h2>
                <p class="mt-4 text-base leading-7 text-muted">{{ __('Each service does its own job, and they share what they know: a deploy becomes a release marker, an incident points at the release that caused it, and traffic reports show what shipped.') }}</p>
            </div>
            <ol class="grid grid-cols-2 gap-3 sm:gap-4">
                @foreach (config('marketing.workflow') as [$number, $title, $text, $icon])
                    <li class="ui-card bg-surface/70 p-4 sm:p-5">
                        <div class="flex items-center justify-between"><span class="grid h-10 w-10 place-items-center rounded-card bg-surface-muted text-primary" aria-hidden="true"><x-signal.ui.icon :name="$icon" class="h-[18px] w-[18px]" /></span><span class="text-xs font-extrabold text-subtle">{{ $number }}</span></div>
                        <h3 class="mt-4 text-base font-extrabold text-ink sm:mt-6">{{ __($title) }}</h3>
                        <p class="mt-2 hidden text-sm leading-6 text-muted sm:block">{{ __($text) }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Why one platform (the workflow above says it in brief on phones). --}}
    <section class="mx-auto hidden max-w-6xl px-5 py-16 sm:block sm:px-8 sm:py-24" aria-labelledby="together-heading">
        <div class="max-w-2xl">
            <p class="ui-eyebrow">{{ __('Better together') }}</p>
            <h2 id="together-heading" class="mt-3 text-3xl font-extrabold tracking-[-0.04em] text-ink sm:text-4xl">{{ __('Built as one platform, not bolted together.') }}</h2>
        </div>
        <div class="mt-10 grid gap-4 sm:grid-cols-2">
            @foreach (config('marketing.integrations') as [$title, $text])
                <div class="ui-card p-5"><p class="font-extrabold text-ink">{{ __($title) }}</p><p class="mt-2 text-sm leading-6 text-muted">{{ __($text) }}</p></div>
            @endforeach
        </div>
    </section>

    {{-- Start. --}}
    <section class="mx-auto max-w-6xl px-5 py-12 sm:px-8 sm:pb-24 sm:pt-0">
        <div class="ui-emphasis relative overflow-hidden rounded-panel px-6 py-10 sm:px-12 sm:py-16">
            <div class="absolute -right-20 -top-24 h-72 w-72 rounded-full bg-primary/30 blur-3xl" aria-hidden="true"></div>
            <div class="relative grid gap-10 lg:grid-cols-[1fr_0.8fr] lg:items-end">
                <div>
                    <p class="text-xs font-extrabold uppercase tracking-[0.15em] text-brand-300">{{ __('Start free') }}</p>
                    <h2 class="mt-4 max-w-2xl text-3xl font-extrabold tracking-[-0.04em] sm:text-4xl">{{ __('Every service has a free tier. Upgrade the ones that grow.') }}</h2>
                    <p class="ui-emphasis-muted mt-4 max-w-xl text-base leading-7">{{ __('No card to start. Each service has its own plan on one monthly bill, and you can change or cancel any of them whenever you like.') }}</p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="{{ route('register') }}" class="ui-btn ui-btn-lg border border-white bg-white text-slate-950 hover:bg-white/90">{{ __('Create your account') }}</a>
                        <a href="{{ route('pricing') }}" class="ui-btn ui-btn-lg border border-white/25 bg-transparent text-white hover:bg-white/10">{{ __('See pricing') }}</a>
                    </div>
                </div>
                <div class="hidden gap-3 sm:grid sm:grid-cols-3 lg:grid-cols-1">
                    @foreach ([['Your cloud', 'Servers run in the provider accounts you connect.'], ['Your data', 'Secrets and scripts are encrypted; analytics sets no cookies.'], ['Your team', 'Roles, per-service access and a full audit log.']] as [$title, $text])
                        <div class="rounded-card border border-white/10 bg-white/5 p-4"><p class="text-sm font-extrabold text-white">{{ __($title) }}</p><p class="mt-1 text-xs leading-5 text-white/65">{{ __($text) }}</p></div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
</x-signal.layouts.public>
