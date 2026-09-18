<x-layouts.core
    :title="$page->name"
    :description="$page->description"
    :canonical="url('/status/'.$page->slug)"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-primary focus:px-4 focus:py-3 focus:font-semibold focus:text-primary focus:shadow-xl">
        {{ __('Skip to main content') }}
    </a>

    <main id="main-content" tabindex="-1" class="min-h-screen bg-secondary px-4 py-10 sm:px-6 sm:py-16">
        <div class="mx-auto max-w-3xl">
            <header class="text-center">
                <a href="{{ url('/') }}" class="inline-flex min-h-[2.5rem] items-center text-xs font-black uppercase tracking-[.24em] text-ternary">{{ config('app.name') }}</a>
                <h1 class="mt-3 text-4xl font-black tracking-tight text-primary">{{ $page->name }}</h1>
                @if ($page->description)
                    <p class="mx-auto mt-3 max-w-xl text-secondary">{{ $page->description }}</p>
                @endif
            </header>

            @php($operational = collect($components)->every(fn ($component) => $component['operational']))

            <x-ui.alert class="mt-10" :tone="$operational ? 'success' : 'warning'" role="status" aria-live="polite">
                <div class="flex items-center gap-3">
                    <span class="h-3 w-3 shrink-0 rounded-full {{ $operational ? 'bg-green-500' : 'bg-amber-500' }}" aria-hidden="true"></span>
                    <h2 class="text-xl font-black">{{ $operational ? __('All systems operational') : __('Some systems are degraded') }}</h2>
                </div>
            </x-ui.alert>

            <x-ui.card class="mt-6 overflow-hidden" aria-label="{{ __('Services') }}">
                @foreach ($components as $statusComponent)
                    <article class="flex flex-wrap items-center gap-4 border-b border-primary p-5 last:border-0">
                        <div class="min-w-0 flex-1">
                            <h2 class="font-bold text-primary">{{ $statusComponent['name'] }}</h2>
                            <p class="mt-1 text-xs text-secondary">
                                {{ $statusComponent['uptime_30d'] !== null ? __(':uptime% uptime over 30 days', ['uptime' => $statusComponent['uptime_30d']]) : __('Uptime history is being collected') }}
                            </p>
                        </div>
                        <x-ui.badge :tone="$statusComponent['operational'] ? 'success' : 'warning'">
                            {{ __($statusComponent['status']) }}
                        </x-ui.badge>
                    </article>
                @endforeach
            </x-ui.card>

            @if ($incidents->isNotEmpty())
                <section class="mt-8" aria-labelledby="incident-history-heading">
                    <div class="flex items-end justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Recent updates') }}</p>
                            <h2 id="incident-history-heading" class="mt-1 text-xl font-black text-primary">{{ __('Incident and maintenance history') }}</h2>
                        </div>
                        <x-ui.badge tone="neutral">{{ $incidents->count() }}</x-ui.badge>
                    </div>
                    <div class="mt-4 space-y-3">
                        @foreach ($incidents as $incident)
                            <x-ui.card class="p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ str($incident->kind)->headline() }} · {{ str($incident->severity)->headline() }}</p>
                                        <h3 class="mt-1 font-black text-primary">{{ $incident->title }}</h3>
                                    </div>
                                    <x-ui.badge tone="neutral">{{ str($incident->status)->headline() }}</x-ui.badge>
                                </div>
                                <p class="mt-3 whitespace-pre-wrap text-sm text-secondary">{{ $incident->message }}</p>
                                <p class="mt-3 text-xs text-secondary">{{ $incident->starts_at->utc()->format('M j, Y H:i').' UTC' }}@if ($incident->ends_at) – {{ $incident->ends_at->utc()->format('M j, Y H:i').' UTC' }}@endif</p>
                            </x-ui.card>
                        @endforeach
                    </div>
                </section>
            @endif

            <x-ui.card class="mt-8 p-5 sm:p-6">
                <h2 class="font-black text-primary">{{ __('Get status updates') }}</h2>
                <p class="mt-1 text-sm text-secondary">{{ __('Receive incident and planned-maintenance updates by email. Confirmation is required.') }}</p>
                @if (session('status_subscription'))
                    <x-ui.alert class="mt-4" tone="success" role="status">{{ session('status_subscription') }}</x-ui.alert>
                @endif
                <form method="POST" action="{{ route('status.subscriptions.store', $page->slug) }}" class="mt-4 flex flex-col gap-3 sm:flex-row">
                    @csrf
                    <label class="sr-only" for="status-email">{{ __('Email address') }}</label>
                    <input id="status-email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" @if ($errors->has('email')) aria-describedby="status-email-error" @endif class="input secondary min-w-0 flex-1 rounded-lg" placeholder="you@example.com">
                    <x-ui.button type="submit" variant="primary">{{ __('Subscribe') }}</x-ui.button>
                </form>
                @error('email')
                    <p id="status-email-error" class="mt-2 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </x-ui.card>

            <footer class="mt-8 text-center text-xs text-secondary">
                {{ __('Updated :time', ['time' => now()->utc()->format('Y-m-d H:i').' UTC']) }} ·
                <a href="{{ route('status.report', $page->slug) }}" class="underline hover:text-primary">JSON</a>
            </footer>
        </div>
    </main>
</x-layouts.core>
