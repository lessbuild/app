{{-- The service's accent, the other services, and breadcrumbs and the questions below for search results. --}}
@php
    $accent = $copy['accent'];
    $others = collect($services)->reject(fn ($other): bool => $other->key() === $service->key());
    $structuredData = array_values(array_filter([\App\Support\StructuredData::breadcrumbs([config('app.name') => route('home'), $service->name() => route('features', $service->key())]),
        $copy['questions'] !== [] ? \App\Support\StructuredData::faq(array_map(fn (array $pair): array => [__($pair[0]), __($pair[1])], $copy['questions'])) : null]));
@endphp

{{-- A service's page, laid out like the Signal product pages. Long lists collapse to titles on phones. --}}
<x-signal.layouts.public :title="$service->name().': '.__($copy['eyebrow'])" :description="__($copy['summary'])" :canonical="route('features', $service->key())" :image="file_exists(public_path('images/og/'.$service->key().'.png')) ? asset('images/og/'.$service->key().'.png') : null" :structured-data="$structuredData">
    <section class="border-b border-line bg-surface" aria-labelledby="service-heading">
        <div class="mx-auto grid max-w-6xl items-center gap-8 px-5 py-10 sm:px-8 sm:py-16 lg:grid-cols-[.95fr_1.05fr] lg:gap-12 lg:py-20">
            <div class="min-w-0">
                <x-signal.ui.link :href="route('home').'#services'" layout="inline" size="inline" variant="muted" class="font-bold">
                    <span aria-hidden="true">←</span> {{ __('All services') }}
                </x-signal.ui.link>
                <p class="product-accent-{{ $accent }} mt-6 flex items-center gap-2 text-xs font-extrabold uppercase tracking-[0.15em] sm:mt-7">
                    <x-signal.ui.icon :name="$copy['icon']" class="size-4" /> {{ __($copy['eyebrow']) }} · {{ $service->name() }}
                </p>
                <h1 id="service-heading" class="mt-4 max-w-2xl text-4xl font-extrabold leading-[1.04] tracking-[-0.05em] text-ink sm:text-6xl">{{ __($copy['headline']) }}</h1>
                <p class="mt-5 max-w-2xl text-base leading-7 text-muted sm:text-lg sm:leading-8">{{ __($copy['summary']) }}</p>
                <ul class="mt-6 grid gap-2" aria-label="{{ __(':service highlights', ['service' => $service->name()]) }}">
                    @foreach ($copy['card_features'] as $feature)
                        <li class="flex items-center gap-2 text-sm font-semibold text-ink"><x-signal.ui.icon name="check" class="size-4 shrink-0 text-success" /><span>{{ __($feature) }}</span></li>
                    @endforeach
                </ul>
                <div class="mt-8 flex flex-col gap-3 min-[440px]:flex-row">
                    <x-signal.ui.button :href="route('register')" variant="primary" size="lg" class="justify-center">{{ __('Start free') }} <x-signal.ui.icon name="arrow-right" class="size-4" /></x-signal.ui.button>
                    <x-signal.ui.button :href="route('pricing').'#'.$service->key()" variant="secondary" size="lg" class="justify-center">{{ __(':service pricing', ['service' => $service->name()]) }}</x-signal.ui.button>
                </div>
            </div>
            <x-signal.blocks.service-preview :copy="$copy" />
        </div>
    </section>

    <section class="border-b border-line bg-surface" aria-labelledby="glance-heading">
        <div class="mx-auto flex max-w-6xl flex-col gap-3 px-5 py-5 sm:px-8 lg:flex-row lg:items-center lg:justify-between">
            <h2 id="glance-heading" class="text-xs font-bold uppercase tracking-widest text-muted">{{ __('Works with') }}</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($copy['capabilities'] as $capability)
                    <li><x-signal.ui.badge class="px-3 py-1.5 text-xs sm:text-sm">{{ __($capability) }}</x-signal.ui.badge></li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="bg-page py-12 sm:py-20" aria-labelledby="highlights-heading">
        <div class="mx-auto max-w-6xl px-5 sm:px-8">
            <div class="mx-auto max-w-3xl sm:text-center">
                <p class="product-accent-{{ $accent }} text-xs font-extrabold uppercase tracking-[0.15em]">{{ __($copy['eyebrow']) }}</p>
                <h2 id="highlights-heading" class="mt-3 text-3xl font-extrabold tracking-[-0.04em] text-ink sm:text-4xl">{{ __($copy['highlights_heading']) }}</h2>
            </div>
            <div @class(['mt-8 grid gap-3 sm:gap-4 md:grid-cols-2', 'xl:grid-cols-4' => count($copy['highlights']) >= 4, 'xl:grid-cols-3' => count($copy['highlights']) < 4])>
                @foreach ($copy['highlights'] as $highlight)
                    <x-signal.ui.card as="article" class="flex gap-4 p-4 sm:block sm:p-6">
                        <span class="product-icon-{{ $accent }} grid size-10 shrink-0 place-items-center rounded-xl sm:size-11" aria-hidden="true"><x-signal.ui.icon :name="$highlight['icon']" class="size-5" /></span>
                        <div>
                            <h3 class="text-base font-extrabold text-ink sm:mt-5 sm:text-lg">{{ __($highlight['title']) }}</h3>
                            <p class="mt-1 text-sm leading-6 text-muted sm:mt-2">{{ __($highlight['text']) }}</p>
                        </div>
                    </x-signal.ui.card>
                @endforeach
            </div>
        </div>
    </section>

    @if (file_exists(public_path('images/screens/'.$service->key().'.png')))
        <section class="bg-page pb-12 sm:pb-20" aria-labelledby="screenshot-heading">
            <div class="mx-auto max-w-6xl px-5 sm:px-8">
                <h2 id="screenshot-heading" class="sr-only">{{ __('What :service looks like', ['service' => $service->name()]) }}</h2>
                <figure class="overflow-hidden rounded-panel border border-line bg-surface shadow-panel">
                    <div class="flex items-center gap-1.5 border-b border-line bg-surface-muted px-4 py-3" aria-hidden="true"><span class="size-2.5 rounded-full bg-danger/60"></span><span class="size-2.5 rounded-full bg-warning/60"></span><span class="size-2.5 rounded-full bg-success/60"></span></div>
                    <img src="{{ asset('images/screens/'.$service->key().'.png') }}" alt="{{ __('A screenshot of :service in :app', ['service' => $service->name(), 'app' => config('app.name')]) }}" width="1360" height="860" loading="lazy" class="block h-auto w-full">
                    <figcaption class="border-t border-line px-4 py-3 text-xs text-muted">{{ __('The real :service, with sample data.', ['service' => $service->name()]) }}</figcaption>
                </figure>
            </div>
        </section>
    @endif

    <section id="capabilities" class="border-y border-line bg-surface-muted/60 py-12 sm:py-20" aria-labelledby="capabilities-heading">
        <div class="mx-auto max-w-6xl px-5 sm:px-8">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="ui-eyebrow">{{ __('Inside :service', ['service' => $service->name()]) }}</p>
                    <h2 id="capabilities-heading" class="mt-3 text-3xl font-extrabold tracking-[-0.04em] text-ink sm:text-4xl">{{ __('What you can do') }}</h2>
                </div>
                <p class="hidden max-w-xl text-sm leading-6 text-muted sm:block">{{ __('A closer look at what :service does, grouped by the work it supports.', ['service' => $service->name()]) }}</p>
            </div>
            <div class="mt-8 grid gap-4 sm:gap-5 lg:grid-cols-2">
                @foreach ($copy['groups'] as $group)
                    <x-signal.ui.card as="article" class="p-5 sm:p-7" aria-label="{{ __($group['label']) }}">
                        <p class="product-accent-{{ $accent }} text-xs font-extrabold uppercase tracking-[0.14em]">{{ __($group['label']) }}</p>
                        <h3 class="mt-2 text-xl font-extrabold text-ink sm:text-2xl">{{ __($group['title']) }}</h3>
                        <p class="mt-2 text-sm leading-6 text-muted">{{ __($group['description']) }}</p>
                        <ul class="mt-4 grid gap-2 sm:mt-5 sm:grid-cols-2 sm:gap-3">
                            @foreach ($group['features'] as [$title, $text])
                                <li class="flex gap-3 rounded-control border border-line bg-surface p-3 sm:p-4">
                                    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-emphasis text-emphasis-ink" aria-hidden="true"><x-signal.ui.icon name="check" class="size-3" /></span>
                                    <div>
                                        <h4 class="text-sm font-bold text-ink">{{ __($title) }}</h4>
                                        <p class="mt-1 hidden text-sm leading-6 text-muted sm:block">{{ __($text) }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </x-signal.ui.card>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-page py-12 sm:py-20" aria-labelledby="workflows-heading">
        <div class="mx-auto grid max-w-6xl gap-8 px-5 sm:px-8 lg:grid-cols-2 lg:gap-10">
            <div>
                <p class="ui-eyebrow">{{ __('A practical path through :service', ['service' => $service->name()]) }}</p>
                <h2 id="workflows-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink">{{ __($copy['workflows_heading']) }}</h2>
                <p class="mt-3 max-w-xl text-sm leading-7 text-muted">{{ __($copy['workflows_intro']) }}</p>
                <ol class="mt-6 grid gap-3">
                    @foreach ($copy['workflows'] as [$title, $text])
                        <li class="ui-card flex gap-4 p-4">
                            <span class="product-icon-{{ $accent }} grid size-8 shrink-0 place-items-center rounded-full text-xs font-extrabold">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <div>
                                <h3 class="font-bold text-ink">{{ __($title) }}</h3>
                                <p class="mt-1 text-sm leading-6 text-muted">{{ __($text) }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
            <section class="ui-emphasis relative overflow-hidden rounded-panel p-6 sm:p-8" aria-labelledby="guardrails-heading">
                <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-brand-300">{{ __('Safety and accountability') }}</p>
                <h2 id="guardrails-heading" class="mt-3 text-2xl font-extrabold tracking-tight text-emphasis-ink">{{ __($copy['guardrails_title']) }}</h2>
                <p class="ui-emphasis-muted mt-3 leading-7">{{ __($copy['guardrails_description']) }}</p>
                <ul class="mt-6 grid grid-cols-2 gap-3">
                    @foreach ($copy['guardrails'] as $guardrail)
                        <li class="flex items-start gap-2 text-sm font-semibold text-emphasis-ink"><x-signal.ui.icon name="check" class="mt-0.5 size-4 shrink-0" /><span>{{ __($guardrail) }}</span></li>
                    @endforeach
                </ul>
            </section>
        </div>
    </section>

    <section class="border-y border-line bg-surface-muted/60 py-12 sm:py-20" aria-labelledby="together-heading">
        <div class="mx-auto max-w-6xl px-5 sm:px-8">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="ui-eyebrow">{{ __('Better together') }}</p>
                    <h2 id="together-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink">{{ __('Works with the rest of :app.', ['app' => config('app.name')]) }}</h2>
                </div>
                <ul class="grid max-w-2xl gap-2">
                    @foreach ($copy['together'] as [$name, $text])
                        <li class="text-sm leading-6 text-muted"><span class="font-bold text-ink">{{ __($name) }}:</span> {{ __($text) }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="mt-7 grid gap-3 sm:grid-cols-3 sm:gap-4">
                @foreach ($others as $other)
                    @php($otherCopy = config('marketing.services.'.$other->key()))
                    <a href="{{ route('features', $other->key()) }}" class="ui-card ui-card--interactive flex items-center gap-4 p-4 sm:block sm:p-6">
                        <span class="product-icon-{{ $otherCopy['accent'] }} grid size-10 shrink-0 place-items-center rounded-xl" aria-hidden="true"><x-signal.ui.icon :name="$otherCopy['icon']" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="product-accent-{{ $otherCopy['accent'] }} block text-xs font-extrabold uppercase tracking-[0.14em] sm:mt-4">{{ __($otherCopy['eyebrow']) }}</span>
                            <span class="mt-1 block text-lg font-extrabold text-ink">{{ $other->name() }}</span>
                            <span class="mt-2 hidden text-sm leading-6 text-muted sm:block">{{ __($otherCopy['card_summary']) }}</span>
                        </span>
                        <x-signal.ui.icon name="arrow-right" class="size-4 shrink-0 text-muted sm:hidden" />
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    @if ($copy['questions'] !== [])
        <section class="bg-page py-12 sm:py-20" aria-labelledby="questions-heading">
            <div class="mx-auto grid max-w-6xl gap-8 px-5 sm:px-8 lg:grid-cols-[.7fr_1.3fr]">
                <div>
                    <p class="ui-eyebrow">{{ __('Good to know') }}</p>
                    <h2 id="questions-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink">{{ __('Straight answers about :service.', ['service' => $service->name()]) }}</h2>
                </div>
                <div class="grid gap-2">
                    @foreach ($copy['questions'] as [$question, $answer])
                        <x-signal.ui.disclosure :title="__($question)"><p class="text-sm leading-6 text-muted">{{ __($answer) }}</p></x-signal.ui.disclosure>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="bg-page px-5 pb-12 sm:px-8 sm:pb-20">
        <div class="ui-emphasis relative mx-auto max-w-6xl overflow-hidden rounded-panel p-6 sm:p-10">
            <div class="absolute -right-16 -top-20 h-64 w-64 rounded-full bg-primary/30 blur-3xl" aria-hidden="true"></div>
            <div class="relative flex flex-col justify-between gap-7 md:flex-row md:items-end">
                <div class="max-w-2xl">
                    <p class="text-xs font-extrabold uppercase tracking-[0.15em] text-brand-300">{{ __('Part of :app', ['app' => config('app.name')]) }}</p>
                    <h2 class="mt-4 text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('Start with :service.', ['service' => $service->name()]) }}</h2>
                    <p class="ui-emphasis-muted mt-4 max-w-xl leading-7">{{ __('Every service has a free tier. Turn on the others when a project needs them, on the same account and bill.') }}</p>
                </div>
                <a href="{{ route('register') }}" class="ui-btn ui-btn-lg shrink-0 border border-white bg-white text-slate-950 hover:bg-white/90">{{ __('Start free') }} <x-signal.ui.icon name="arrow-right" class="size-4" /></a>
            </div>
        </div>
    </section>
</x-signal.layouts.public>
