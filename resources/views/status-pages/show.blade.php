@php($tone = fn (string $state): string => match ($state) { 'operational' => 'success', 'major_outage' => 'danger', 'maintenance' => 'info', default => 'warning' })

<x-signal.layouts.base :title="$page->name" :description="$page->description ?: __('Live service status and recent incident history.')" indexable :canonical="$page->publicUrl()">
    <main id="main-content" tabindex="-1" class="mx-auto grid max-w-4xl gap-6 px-4 py-10 sm:px-8 sm:py-14">
        <header class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="ui-eyebrow">{{ $page->account->name }}</p>
                <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ $page->name }}</h1>
                @if ($page->description)
                    <p class="mt-3 max-w-2xl whitespace-pre-line text-sm leading-6 text-muted">{{ $page->description }}</p>
                @endif
            </div>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-card bg-ink text-surface" aria-hidden="true">↗</span>
        </header>

        @if (session('status'))
            <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
        @endif

        <section aria-live="polite" @class([
            'flex items-center gap-4 rounded-card border p-5 sm:p-6',
            'border-success bg-success-soft' => $overall === 'operational',
            'border-warning bg-warning-soft' => $overall === 'degraded',
            'border-danger bg-danger-soft' => $overall === 'major_outage',
            'border-info bg-info-soft' => $overall === 'maintenance',
        ])>
            <x-signal.ui.status-dot size="lg" :color="'var(--ui-'.$tone($overall).')'" aria-hidden="true" />
            <div>
                <h2 class="text-lg font-extrabold text-ink">{{ $overallLabel }}</h2>
                <p class="mt-0.5 text-sm text-muted">{{ __('Updated :time UTC', ['time' => now('UTC')->format('Y-m-d H:i')]) }}</p>
            </div>
        </section>

        @if ($activeUpdates !== [])
            <section class="grid gap-3" aria-labelledby="active-updates">
                <h2 id="active-updates" class="sr-only">{{ __('Current incidents and maintenance') }}</h2>
                @foreach ($activeUpdates as $update)
                    <x-signal.ui.alert :tone="$update->kind === 'maintenance' ? 'info' : ($update->severity === 'critical' ? 'danger' : 'warning')">
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-wide">{{ $update->kind === 'maintenance' ? __('Maintenance') : __('Incident') }} · {{ $update->statusLabel() }}</p>
                            <p class="mt-1 font-extrabold">{{ $update->title }}</p>
                            <p class="mt-1 whitespace-pre-line text-sm">{{ $update->message }}</p>
                            <p class="mt-2 text-xs">{{ __('Started :time UTC', ['time' => $update->starts_at->format('Y-m-d H:i')]) }} · {{ __('Last updated :time', ['time' => $update->updated_at?->diffForHumans()]) }}</p>
                        </div>
                    </x-signal.ui.alert>
                @endforeach
            </section>
        @endif

        <x-signal.ui.card class="overflow-hidden">
            <div class="border-b border-line px-5 py-4 sm:px-6"><h2 class="font-extrabold text-ink">{{ __('Systems') }}</h2></div>
            <div class="divide-y divide-line">
                @forelse ($components as $row)
                    <article class="px-5 py-5 sm:px-6">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="font-bold text-ink">{{ $row['name'] }}</h3>
                                <p class="mt-0.5 text-xs text-muted">{{ $row['type'] }}@if ($row['checkedAt']) · {{ __('Checked :time', ['time' => $row['checkedAt']->diffForHumans()]) }}@endif</p>
                            </div>
                            <x-signal.ui.badge :tone="$tone($row['state'])">{{ $row['stateLabel'] }}</x-signal.ui.badge>
                        </div>
                        @foreach ($row['incidents'] as $incident)
                            <p class="mt-3 text-sm text-danger">{{ $incident->title }} · {{ __('since :time', ['time' => $incident->opened_at->diffForHumans()]) }}</p>
                        @endforeach
                        @if ($row['history'])
                            <div class="mt-4">
                                <div class="flex items-end justify-between gap-2 text-xs text-muted">
                                    <span>{{ __('Last 30 days') }}</span>
                                    <span class="font-bold text-ink">{{ $row['history']['uptime'] !== null ? __(':uptime% uptime', ['uptime' => number_format($row['history']['uptime'], 2)]) : __('Not enough data') }}</span>
                                </div>
                                <div class="mt-2 flex gap-0.5" role="img" aria-label="{{ __('30-day history for :component: :uptime', ['component' => $row['name'], 'uptime' => $row['history']['uptime'] !== null ? number_format($row['history']['uptime'], 2).'%' : __('not enough data')]) }}">
                                    @foreach ($row['history']['days'] as $day)
                                        <span title="{{ $day['label'] }} · {{ $day['summary'] }}" @class([
                                            'h-8 min-w-0 flex-1 rounded-sm',
                                            'bg-success' => $day['state'] === 'operational',
                                            'bg-danger' => $day['state'] === 'outage',
                                            'bg-warning' => $day['state'] === 'degraded',
                                            'bg-line' => $day['state'] === 'no_data',
                                        ])></span>
                                    @endforeach
                                </div>
                                <div class="mt-1 flex justify-between text-xs text-muted"><span>{{ __('30 days ago') }}</span><span>{{ __('Today') }}</span></div>
                            </div>
                        @endif
                    </article>
                @empty
                    <p class="px-5 py-8 text-sm text-muted sm:px-6">{{ __('No systems have been added to this page yet.') }}</p>
                @endforelse
            </div>
        </x-signal.ui.card>

        @if ($upcomingMaintenance !== [])
            <x-signal.ui.card class="p-5 sm:p-6">
                <h2 class="font-extrabold text-ink">{{ __('Planned maintenance') }}</h2>
                <ul class="mt-3 grid gap-3">
                    @foreach ($upcomingMaintenance as $update)
                        <li>
                            <p class="font-bold text-ink">{{ $update->title }}</p>
                            <p class="text-xs text-muted">{{ $update->starts_at->format('Y-m-d H:i') }}@if ($update->ends_at) – {{ $update->ends_at->format('Y-m-d H:i') }}@endif UTC</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-muted">{{ $update->message }}</p>
                        </li>
                    @endforeach
                </ul>
            </x-signal.ui.card>
        @endif

        <x-signal.ui.card class="p-5 sm:p-6">
            <h2 class="font-extrabold text-ink">{{ __('Get updates by email') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('We’ll email you when we post an incident or maintenance update. You can unsubscribe from any email.') }}</p>
            <form method="POST" action="{{ route('status.subscribe', $page->slug) }}" class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                <div class="min-w-0 flex-1 basis-64">
                    <x-signal.ui.input-field name="email" type="email" :label="__('Email address')" autocomplete="email" maxlength="254" required />
                </div>
                <x-signal.ui.button type="submit" variant="primary">{{ __('Subscribe') }}</x-signal.ui.button>
            </form>
            <details class="mt-4 text-sm">
                <summary class="cursor-pointer font-bold text-primary">{{ __('Post updates to Slack or a webhook instead') }}</summary>
                @if (session('webhook_secret'))
                    <x-signal.ui.alert tone="info" class="mt-3">{{ __('Your signing secret (shown once): :secret — requests carry X-BuildPusher-Signature: v1=HMAC-SHA256 of the timestamp, a dot and the body.', ['secret' => session('webhook_secret')]) }}</x-signal.ui.alert>
                @endif
                <form method="POST" action="{{ route('status.subscribe.webhook', $page->slug) }}" class="mt-3 grid items-end gap-3 sm:grid-cols-[10rem_1fr_auto]">
                    @csrf
                    <x-signal.ui.select-field name="channel" :label="__('Where')">
                        <option value="slack">Slack</option>
                        <option value="webhook">{{ __('Signed webhook') }}</option>
                    </x-signal.ui.select-field>
                    <x-signal.ui.input-field name="url" type="url" :label="__('Incoming webhook URL')" maxlength="2048" placeholder="https://hooks.slack.com/services/…" required />
                    <x-signal.ui.button type="submit" variant="secondary">{{ __('Subscribe') }}</x-signal.ui.button>
                </form>
            </details>
        </x-signal.ui.card>

        @if ($pastUpdates !== [] || $recentIncidents !== [])
            <x-signal.ui.card class="overflow-hidden">
                <div class="border-b border-line px-5 py-4 sm:px-6">
                    <h2 class="font-extrabold text-ink">{{ __('Past 30 days') }}</h2>
                </div>
                <ul class="divide-y divide-line">
                    @foreach ($pastUpdates as $update)
                        <li class="px-5 py-4 sm:px-6">
                            <p class="font-bold text-ink">{{ $update->title }}</p>
                            <p class="text-xs text-muted">{{ $update->statusLabel() }} · {{ $update->starts_at->format('Y-m-d H:i') }} UTC</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-muted">{{ $update->message }}</p>
                            @foreach (['root_cause' => __('What happened'), 'remediation' => __('What we did'), 'follow_up' => __('What’s next')] as $field => $label)
                                @if (filled($update->{$field}))
                                    <p class="mt-2 text-sm"><span class="font-bold text-ink">{{ $label }}:</span> <span class="whitespace-pre-line text-muted">{{ $update->{$field} }}</span></p>
                                @endif
                            @endforeach
                        </li>
                    @endforeach
                    @foreach ($recentIncidents as $incident)
                        <li class="px-5 py-4 sm:px-6">
                            <p class="font-bold text-ink">{{ $incident->title }}</p>
                            <p class="text-xs text-muted">{{ __('Resolved') }} · {{ $incident->opened_at->format('Y-m-d H:i') }}@if ($incident->resolved_at) – {{ $incident->resolved_at->format('Y-m-d H:i') }}@endif UTC</p>
                        </li>
                    @endforeach
                </ul>
            </x-signal.ui.card>
        @endif

        <footer class="flex flex-wrap justify-between gap-3 text-xs text-muted">
            <span>{{ __('Powered by :app', ['app' => config('app.name')]) }}</span>
            <a href="{{ route('status.report', $page->slug) }}" class="hover:underline">{{ __('JSON') }}</a>
        </footer>
    </main>
</x-signal.layouts.base>
