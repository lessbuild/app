<x-layouts.core :title="__('Pricing')" :description="__('Simple monthly or annual pricing for BuildPusher.')" :canonical="route('pricing')" :indexable="true" :livewire="false">
    <main class="min-h-screen bg-secondary px-4 py-10 sm:px-6 lg:px-8" x-data="{ interval: 'yearly' }">
        <div class="mx-auto max-w-7xl">
            <nav class="flex items-center justify-between"><a href="/" class="text-xl font-black uppercase tracking-tight text-primary">{{ config('app.name') }}</a><a href="{{ route('login') }}" class="button primary">{{ __('Sign in') }}</a></nav>
            <header class="mx-auto max-w-3xl py-12 text-center"><p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Simple pricing') }}</p><h1 class="mt-4 text-4xl font-black tracking-tight text-primary sm:text-6xl">{{ __('From first push to serious scale.') }}</h1><p class="mt-5 text-lg text-secondary">{{ __('Deploy to your own cloud with previews, rollbacks, monitoring and backups in one calm control plane.') }}</p>
                <div class="mt-7 inline-flex rounded-xl border border-primary bg-primary p-1"><button type="button" @click="interval='monthly'" :class="interval==='monthly' ? 'bg-ternary text-white' : 'text-secondary'" class="rounded-lg px-5 py-2 text-sm font-bold">{{ __('Monthly') }}</button><button type="button" @click="interval='yearly'" :class="interval==='yearly' ? 'bg-ternary text-white' : 'text-secondary'" class="rounded-lg px-5 py-2 text-sm font-bold">{{ __('Yearly · save 2 months') }}</button></div>
            </header>
            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                @foreach($plans as $key => $plan)
                    <article @class(['relative flex flex-col rounded-2xl border bg-primary p-7 shadow-xs', 'border-ternary ring-2 ring-ternary' => $key === 'pro', 'border-primary' => $key !== 'pro'])>
                        @if($key === 'pro')<span class="absolute -top-3 left-6 rounded-full bg-ternary px-3 py-1 text-xs font-bold uppercase text-white">{{ __('Most popular') }}</span>@endif
                        <h2 class="text-xl font-black text-primary">{{ $plan['name'] }}</h2><p class="mt-2 min-h-12 text-secondary">{{ $plan['description'] }}</p>
                        <p class="mt-7 text-primary"><span class="text-4xl font-black" x-text="interval === 'yearly' ? '${{ $plan['yearly_price'] }}' : '${{ $plan['price'] }}'">${{ $plan['yearly_price'] }}</span><span class="text-secondary" x-text="{{ $plan['price'] ? "interval === 'yearly' ? '/year' : '/month'" : "' forever'" }}">{{ $plan['price'] ? __('/year') : __(' forever') }}</span></p>
                        @if($plan['price'])<p x-show="interval==='yearly'" class="mt-1 text-xs font-semibold text-ternary">{{ __('Equivalent to $:price/month', ['price' => number_format($plan['yearly_price'] / 12, 2)]) }}</p>@endif
                        <ul class="my-7 flex-1 space-y-3 text-secondary">@foreach($plan['features'] as $feature)<li class="flex gap-3"><span class="font-bold text-ternary">✓</span>{{ $feature }}</li>@endforeach</ul>
                        @php($planUrl = auth()->check() ? route('billing.index') : ($registrationOpen ? route('register') : route('access-request.create', ['plan' => $key])))
                        <a href="{{ $planUrl }}" class="rounded-lg bg-ternary px-5 py-3 text-center font-bold text-white">{{ $registrationOpen ? ($key === 'free' ? __('Start free') : __('Start 14-day trial')) : __('Request access') }}</a>
                    </article>
                @endforeach
            </div>
            <div class="mx-auto mt-8 max-w-3xl space-y-2 text-center text-sm text-secondary"><p>{{ __('Your provider bill stays separate. BuildPusher never marks up infrastructure costs.') }}</p><p>{{ __('Unlimited plans are subject to a reasonable fair-use policy to prevent abusive or automated misuse.') }}</p></div>
            @unless($registrationOpen)<div class="mx-auto mt-8 max-w-3xl rounded-xl border border-primary bg-primary p-5 text-center"><p class="font-bold text-primary">{{ __('BuildPusher is currently onboarding customers by invitation.') }}</p><p class="mt-1 text-sm text-secondary">{{ __('Request access from any plan and tell us what you operate. No payment details are collected until you accept an invitation.') }}</p></div>@endunless
        </div>
    </main>
</x-layouts.core>
