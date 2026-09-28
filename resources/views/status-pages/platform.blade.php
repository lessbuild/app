<x-signal.layouts.base :title="__(':app status', ['app' => config('app.name')])" :description="__('Live status of the platform and its services.')" indexable :canonical="route('platform.status')">
    <main id="main-content" tabindex="-1" class="mx-auto grid max-w-4xl gap-6 px-4 py-10 sm:px-8 sm:py-14">
        <header>
            <p class="ui-eyebrow">{{ config('app.name') }}</p>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('Platform status') }}</h1>
        </header>
        <section aria-live="polite" @class(['flex items-center gap-4 rounded-card border p-5 sm:p-6', 'border-success bg-success-soft' => $snapshot['operational'], 'border-warning bg-warning-soft' => ! $snapshot['operational']])>
            <x-signal.ui.status-dot size="lg" :color="$snapshot['operational'] ? 'var(--ui-success)' : 'var(--ui-warning)'" aria-hidden="true" />
            <div>
                <h2 class="text-lg font-extrabold text-ink">{{ $snapshot['operational'] ? __('All systems operational') : __('Some systems are degraded') }}</h2>
                <p class="mt-0.5 text-sm text-muted">{{ __('Checked :time UTC', ['time' => \Carbon\CarbonImmutable::parse($snapshot['checked_at'])->utc()->format('Y-m-d H:i')]) }}</p>
            </div>
        </section>
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line">
                @foreach ($snapshot['components'] as $part)
                    <li class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="min-w-0"><p class="font-bold text-ink">{{ $part['name'] }}</p><p class="text-sm text-muted">{{ $part['description'] }}</p></div>
                        <x-signal.ui.badge :tone="$part['operational'] ? 'success' : 'warning'">{{ $part['status'] }}</x-signal.ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
        <p class="text-xs text-muted"><a href="{{ route('platform.status.report') }}" class="underline">{{ __('JSON report') }}</a></p>
    </main>
</x-signal.layouts.base>
