<x-signal.layouts.core
    :title="$statusPage->name . ' · ' . str($statusPage->product)->headline() . ' status'"
    :description="$statusPage->description ?: __('Live service status and recent incident history.')"
    :canonical="$canonical"
    :indexable="$indexable ?? false"
    :livewire="false"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.blocks.public-navigation />

    <main id="main-content" tabindex="-1" class="mx-auto max-w-5xl px-5 py-10 sm:px-8 sm:py-14">
        <x-signal.ui.page-header
            :eyebrow="$statusPage->workspaceName"
            :title="$statusPage->name"
            :description="$statusPage->description"
        />

        <section @class([
            'mt-6 rounded-card border p-5 sm:p-6',
            'border-success bg-success-soft' => $statusPage->overall === 'operational',
            'border-warning bg-warning-soft' => $statusPage->overall === 'degraded',
            'border-danger bg-danger-soft' => $statusPage->overall === 'major_outage',
        ]) aria-live="polite">
            <p class="text-lg font-extrabold text-ink">{{ $statusPage->overallLabel }}</p>
            <p class="mt-1 text-sm text-muted">{{ __('Current status across the services listed below.') }}</p>
        </section>

        <x-signal.ui.card class="mt-5 overflow-hidden p-0">
            <div class="border-b border-line px-5 py-4 sm:px-6">
                <h2 class="font-extrabold text-ink">{{ __('Systems') }}</h2>
            </div>
            <div class="divide-y divide-line">
                @forelse ($statusPage->components as $component)
                    <article class="px-5 py-5 sm:px-6">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-bold text-ink">{{ $component['name'] }}</h3>
                                <p class="mt-1 text-xs text-muted">
                                    {{ $component['type'] ?? __('Monitored service') }}
                                    @if ($component['checkedAt']) · {{ __('Checked :time', ['time' => $component['checkedAt']->diffForHumans()]) }} @endif
                                </p>
                            </div>
                            <x-signal.ui.badge :tone="match ($component['state']) { 'operational' => 'success', 'degraded' => 'warning', default => 'danger' }">
                                {{ $component['stateLabel'] }}
                            </x-signal.ui.badge>
                        </div>

                        @if ($component['history'])
                            <div class="mt-4 rounded-card border border-line bg-surface-muted p-4">
                                <div class="flex flex-wrap items-end justify-between gap-2">
                                    <div>
                                        <h4 class="text-sm font-bold text-ink">{{ __('30-day observed uptime') }}</h4>
                                        <p class="mt-1 text-xs text-muted">{{ __('Completed checks from the current monitor configuration.') }}</p>
                                    </div>
                                    @if ($component['history']['uptime'] !== null)
                                        <span class="text-lg font-extrabold text-ink">{{ number_format($component['history']['uptime'], 2) }}%</span>
                                    @else
                                        <span class="text-xs font-semibold text-muted">{{ __('Not enough data') }}</span>
                                    @endif
                                </div>
                                <div class="mt-4 flex gap-1" role="img" aria-label="{{ __('30-day observed uptime history for :component', ['component' => $component['name']]) }}">
                                    @foreach ($component['history']['days'] as $day)
                                        <span
                                            title="{{ $day['label'] }} · {{ $day['summary'] }}"
                                            @class([
                                                'h-7 min-w-0 flex-1 rounded-sm',
                                                'bg-success' => $day['state'] === 'operational',
                                                'bg-danger' => $day['state'] === 'outage',
                                                'bg-warning' => in_array($day['state'], ['degraded', 'unknown'], true),
                                                'bg-line' => $day['state'] === 'no_data',
                                            ])
                                        ></span>
                                    @endforeach
                                </div>
                                <p class="mt-3 text-xs text-muted">
                                    {{ trans_choice(':count observed check|:count observed checks', $component['history']['measured'], ['count' => $component['history']['measured']]) }}
                                    @if ($component['history']['failed'] > 0) · {{ trans_choice(':count failed check|:count failed checks', $component['history']['failed'], ['count' => $component['history']['failed']]) }} @endif
                                    @if ($component['history']['unknown'] > 0) · {{ trans_choice(':count unknown check|:count unknown checks', $component['history']['unknown'], ['count' => $component['history']['unknown']]) }} @endif
                                </p>
                            </div>
                        @elseif (($component['uptime'] ?? null) !== null)
                            <div class="mt-4 rounded-card border border-line bg-surface-muted p-4">
                                <p class="text-xs font-bold text-muted">{{ __('30-day observed uptime') }}</p>
                                <p class="mt-1 text-lg font-extrabold text-ink">{{ number_format((float) $component['uptime'], 2) }}%</p>
                            </div>
                        @else
                            <p class="mt-4 text-xs text-muted">{{ __('No recent check data is available for this system.') }}</p>
                        @endif
                    </article>
                @empty
                    <p class="px-5 py-8 text-sm text-muted sm:px-6">{{ __('No systems have been added to this page yet.') }}</p>
                @endforelse
            </div>
        </x-signal.ui.card>

        @if ($statusPage->incidents->isNotEmpty())
            <x-signal.ui.card class="mt-5 p-5 sm:p-6">
                <h2 class="font-extrabold text-ink">{{ __('Current incidents and maintenance') }}</h2>
                <div class="mt-4 grid gap-3">
                    @foreach ($statusPage->incidents as $incident)
                        <x-signal.ui.alert :tone="data_get($incident, 'kind') === 'maintenance' ? 'info' : 'danger'" class="p-4">
                            @if (data_get($incident, 'kind'))
                                <p class="text-xs font-bold uppercase tracking-wide text-muted">{{ str($incident->kind)->headline() }} · {{ str($incident->severity)->headline() }}</p>
                            @endif
                            <p class="text-sm font-bold">{{ $incident->title }}</p>
                            @if (filled(data_get($incident, 'message')))
                                <p class="mt-2 whitespace-pre-wrap text-sm text-muted">{{ $incident->message }}</p>
                            @endif
                            <p class="mt-1 text-xs text-muted">{{ ucfirst($incident->status) }} · {{ __('Started :time', ['time' => $incident->opened_at->diffForHumans()]) }}</p>
                        </x-signal.ui.alert>
                    @endforeach
                </div>
            </x-signal.ui.card>
        @endif

        @if ($statusPage->recentIncidents->isNotEmpty())
            <x-signal.ui.card class="mt-5 overflow-hidden p-0">
                <div class="border-b border-line px-5 py-4 sm:px-6">
                    <h2 class="font-extrabold text-ink">{{ __('Recent incidents') }}</h2>
                    <p class="mt-1 text-xs text-muted">{{ __('Recent :product status updates.', ['product' => str($statusPage->product)->headline()]) }}</p>
                </div>
                <div class="divide-y divide-line">
                    @foreach ($statusPage->recentIncidents as $incident)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-6">
                            <div>
                                @if (data_get($incident, 'kind'))
                                    <p class="text-xs font-bold uppercase tracking-wide text-subtle">{{ str($incident->kind)->headline() }} · {{ str($incident->severity)->headline() }}</p>
                                @endif
                                <p class="text-sm font-semibold text-ink">{{ $incident->title }}</p>
                                @if (filled(data_get($incident, 'message')))
                                    <p class="mt-2 whitespace-pre-wrap text-sm text-muted">{{ $incident->message }}</p>
                                @endif
                                <p class="mt-1 text-xs text-muted">{{ __('Started :time', ['time' => $incident->opened_at->diffForHumans()]) }}@if ($incident->resolved_at) · {{ __('Resolved :time', ['time' => $incident->resolved_at->diffForHumans()]) }} @endif</p>
                            </div>
                            <x-signal.ui.badge tone="neutral">{{ str($incident->status)->headline() }}</x-signal.ui.badge>
                        </div>
                    @endforeach
                </div>
            </x-signal.ui.card>
        @endif

        @if ($subscriptionAction ?? null)
            <x-signal.ui.card class="mt-5 p-5 sm:p-6">
                <h2 class="font-extrabold text-ink">{{ __('Get status updates') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Receive incident and planned-maintenance updates by email. Confirmation is required.') }}</p>
                @if (session('status_subscription'))
                    <x-signal.ui.alert class="mt-4" tone="success" role="status">{{ session('status_subscription') }}</x-signal.ui.alert>
                @endif
                <form method="POST" action="{{ $subscriptionAction }}" class="mt-4 flex flex-col gap-3 sm:flex-row">
                    @csrf
                    <label class="sr-only" for="status-email">{{ __('Email address') }}</label>
                    <x-signal.ui.input id="status-email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" @if ($errors->has('email')) aria-describedby="status-email-error" @endif class="ui-input min-w-0 flex-1" placeholder="you@example.com" :restore="false" />
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Subscribe') }}</x-signal.ui.button>
                </form>
                @error('email')
                    <p id="status-email-error" class="mt-2 text-sm text-danger">{{ $message }}</p>
                @enderror
            </x-signal.ui.card>
        @endif

        <footer class="mt-8 flex flex-wrap justify-between gap-3 text-xs text-muted">
            <span>{{ __('Powered by Buildpusher :product', ['product' => str($statusPage->product)->headline()]) }}</span>
            <span>{{ __('Updated :time UTC', ['time' => now()->format('Y-m-d H:i')]) }}</span>
            @if ($reportUrl ?? null)
                <a class="ui-link" href="{{ $reportUrl }}">{{ __('JSON status report') }}</a>
            @endif
        </footer>
    </main>
</x-signal.layouts.core>
