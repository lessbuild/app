@php($yearly = request()->query('billing') === 'yearly')
@php($money = fn (?int $cents): string => $cents === null ? __('Contact us') : ($cents === 0 ? __('Free') : '$'.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2)))
<x-signal.layouts.public :title="__('Pricing')" :description="__('Each service has its own tiers. Start free and pay for what each project needs.')" :canonical="route('pricing')">
    <div class="mx-auto grid max-w-6xl gap-12 px-5 py-12 sm:px-8 sm:py-16">
    <section class="grid gap-3 text-center">
        <h1 class="text-4xl font-extrabold tracking-tight text-ink">{{ __('Pricing') }}</h1>
        <p class="mx-auto max-w-2xl text-lg text-muted">{{ __('Each service has its own tiers, all on one bill. Start free, change tiers any time, and pay monthly or yearly in US dollars.') }}</p>
        <nav class="mx-auto inline-flex rounded-full border border-line bg-surface p-1 text-sm font-bold" aria-label="{{ __('Billing period') }}">
            <a href="{{ route('pricing') }}" @class(['rounded-full px-4 py-1.5', 'bg-primary-soft text-primary' => ! $yearly, 'text-muted hover:text-ink' => $yearly]) @unless ($yearly) aria-current="page" @endunless>{{ __('Monthly') }}</a>
            <a href="{{ route('pricing', ['billing' => 'yearly']) }}" @class(['rounded-full px-4 py-1.5', 'bg-primary-soft text-primary' => $yearly, 'text-muted hover:text-ink' => ! $yearly]) @if ($yearly) aria-current="page" @endif>{{ __('Yearly · 2 months free') }}</a>
        </nav>
        @if ((int) config('billing.trial_days') > 0)
            <p class="mx-auto"><x-signal.ui.badge tone="success">{{ __('Your first paid plan is free for :days days', ['days' => (int) config('billing.trial_days')]) }}</x-signal.ui.badge></p>
        @endif
    </section>

    @foreach ($services as $service)
        @php($billing = $service->billing())
        <section id="{{ $service->key() }}" class="grid gap-4" aria-labelledby="{{ $service->key() }}-heading">
            <div>
                <h2 id="{{ $service->key() }}-heading" class="text-2xl font-extrabold text-ink">{{ $service->name() }}</h2>
                <p class="text-sm text-muted">{{ $service->tagline() }}</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($billing->tiers as $tier)
                    <x-signal.ui.card class="grid content-start gap-3 p-5">
                        <p class="font-extrabold text-ink">{{ $tier->name }}</p>
                        <p><span class="text-3xl font-extrabold text-ink">{{ $money($yearly ? $tier->yearlyCents() : $tier->monthlyCents) }}</span>@if (($tier->monthlyCents ?? 0) > 0)<span class="text-sm text-muted"> {{ $yearly ? __('/ year') : __('/ month') }}</span>@if ($yearly)<span class="block text-xs text-muted">{{ __(':monthly a month, billed yearly', ['monthly' => $money((int) round(($tier->yearlyCents() ?? 0) / 12))]) }}</span>@endif @endif</p>
                        <p class="text-sm text-muted">{{ $tier->description }}</p>
                        @if ($tier->features !== [])
                            <ul class="grid gap-1 text-sm">
                                @foreach ($tier->features as $feature)
                                    <li>✓ {{ $feature }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </x-signal.ui.card>
                @endforeach
            </div>
            @if ($billing->addOns !== [] || $billing->meters !== [])
                <ul class="grid gap-1 text-sm text-muted">
                    @foreach ($billing->addOns as $addOn)
                        <li>{{ __('Add-on: :name, :price each per month.', ['name' => $addOn->name, 'price' => $money($addOn->monthlyCentsPerUnit)]) }} {{ $addOn->description }}</li>
                    @endforeach
                    @foreach ($billing->meters as $meter)
                        <li>{{ __(':name beyond your tier’s allowance are billed by usage.', ['name' => $meter->name]) }}</li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach
    <section aria-labelledby="compare-heading" class="border-t border-line pt-10">
        <h2 id="compare-heading" class="text-lg font-extrabold text-ink">{{ __('Coming from another tool?') }}</h2>
        <ul class="mt-4 flex flex-wrap gap-2">
            @foreach (config('compare.competitors') as $slug => $competitor)
                <li><a href="{{ route('compare', $slug) }}" class="ui-chip hover:text-ink">{{ __(':app vs :other', ['app' => config('app.name'), 'other' => $competitor['name']]) }}</a></li>
            @endforeach
        </ul>
    </section>
    </div>
</x-signal.layouts.public>
