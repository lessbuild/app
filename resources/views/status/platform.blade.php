<x-layouts.core
    :title="__('Service status')"
    :description="__('Current availability of BuildPusher services.')"
    :canonical="route('platform-status.show')"
    :indexable="true"
    :livewire="false"
>
    <main id="main-content" tabindex="-1" class="min-h-screen bg-secondary px-4 py-10 sm:px-6 sm:py-16">
        <div class="mx-auto max-w-4xl">
            <header class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <a href="{{ url('/') }}" class="inline-flex min-h-[2.5rem] items-center text-sm font-black uppercase tracking-[.18em] text-ternary">{{ config('app.name') }}</a>
                    <h1 class="mt-3 text-3xl font-black tracking-tight text-primary sm:text-4xl">{{ __('Service status') }}</h1>
                    <p class="mt-2 text-secondary">{{ __('Live availability for BuildPusher’s public services.') }}</p>
                </div>
                <x-ui.button :href="route('platform-status.report')" variant="secondary">{{ __('View JSON') }}</x-ui.button>
            </header>

            <x-ui.alert class="mt-10" :tone="$snapshot['operational'] ? 'success' : 'warning'" role="status" aria-live="polite">
                <div class="flex items-center gap-3">
                    <span class="h-3 w-3 shrink-0 rounded-full {{ $snapshot['operational'] ? 'bg-green-500' : 'bg-amber-500' }}" aria-hidden="true"></span>
                    <h2 class="text-xl font-black">{{ $snapshot['operational'] ? __('All systems operational') : __('Some systems are degraded') }}</h2>
                </div>
            </x-ui.alert>

            <x-ui.card class="mt-6 overflow-hidden" aria-label="{{ __('Services') }}">
                @foreach ($snapshot['components'] as $statusComponent)
                    <article class="flex flex-col gap-3 border-b border-primary p-5 last:border-0 sm:flex-row sm:items-center">
                        <div class="min-w-0 flex-1">
                            <h2 class="font-bold text-primary">{{ $statusComponent['name'] }}</h2>
                            <p class="mt-1 text-sm leading-6 text-secondary">{{ $statusComponent['description'] }}</p>
                        </div>
                        <x-ui.badge :tone="$statusComponent['operational'] ? 'success' : 'warning'">
                            {{ __($statusComponent['status']) }}
                        </x-ui.badge>
                    </article>
                @endforeach
            </x-ui.card>

            <footer class="mt-8 flex flex-col gap-2 text-sm text-secondary sm:flex-row sm:items-center sm:justify-between">
                <p>{{ __('Automatically checked at :time', ['time' => \Illuminate\Support\Carbon::parse($snapshot['checked_at'])->format('Y-m-d H:i:s').' UTC']) }}</p>
                <p>{{ __('Detailed infrastructure diagnostics are restricted to workspace administrators.') }}</p>
            </footer>
        </div>
    </main>
</x-layouts.core>
