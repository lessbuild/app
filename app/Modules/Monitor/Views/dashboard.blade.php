@extends('monitor::layouts.app')

@section('title', 'Overview')
@section('breadcrumb', 'Overview')

@section('content')
    <div class="flex flex-col gap-6">
        <x-monitor::ui.page-header eyebrow="Telemetry overview" eyebrow-icon="activity" :title="'Welcome back, '.auth()->user()->name.'.'" :description="$from->format('Y-m-d H:i:s').' – '.$until->format('Y-m-d H:i:s').' UTC · refresh to update'">
            <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <form method="GET" action="{{ route('monitor.dashboard') }}" class="flex items-center gap-2">
                    <x-monitor::ui.select name="range" label="Time range" :value="$range" :options="$rangeOptions" :restore="false" hide-label />
                    <x-monitor::ui.button variant="secondary">Refresh</x-monitor::ui.button>
                </form>
                @if($canManageApplications)
                    <x-monitor::ui.button :href="route('monitor.applications.create')"><x-monitor::icon name="plus" class="h-4 w-4" />Add application</x-monitor::ui.button>
                @endif
            </div>
            </x-slot:actions>
        </x-monitor::ui.page-header>

        @if($onboarding)
            <x-monitor::ui.panel padding="p-5 sm:p-6" aria-labelledby="onboarding-heading">
                <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary dark:text-primary">GET STARTED</p>
                        <h2 id="onboarding-heading" class="mt-2 text-lg font-bold tracking-tight">Build your first reliable signal.</h2>
                        <p class="mt-1 max-w-2xl text-xs leading-5 text-muted dark:text-subtle">Complete the core setup once, then {{ config('app.name') }} keeps collecting context across your stack.</p>
                    </div>
                    <x-monitor::ui.badge tone="violet">{{ $onboarding['completed'] }} of {{ $onboarding['total'] }} complete</x-monitor::ui.badge>
                </div>
                <div class="ui-progress mt-5" role="progressbar" aria-label="Workspace setup progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $onboarding['percentage'] }}"><span style="width: {{ $onboarding['percentage'] }}%"></span></div>
                <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    @foreach($onboarding['items'] as $item)
                        <a href="{{ route($item['route']) }}" @class(['ui-card group p-4 transition hover:-translate-y-0.5 hover:shadow-soft', 'border-success bg-success-soft dark:border-success dark:bg-success-soft' => $item['complete'], 'border-line bg-surface-muted/70 dark:border-line dark:bg-surface/30' => ! $item['complete']])>
                            <span @class(['flex h-8 w-8 items-center justify-center rounded-control text-xs font-bold', 'bg-success-soft text-success' => $item['complete'], 'bg-surface text-primary shadow-soft dark:bg-surface dark:text-primary' => ! $item['complete']])>@if($item['complete'])<x-monitor::icon name="check" class="h-4 w-4" />@else{{ $loop->iteration }}@endif</span>
                            <p class="mt-3 text-xs font-bold text-ink group-hover:text-primary dark:text-ink dark:group-hover:text-primary">{{ $item['label'] }}</p>
                            <p class="mt-1 text-[11px] leading-5 text-muted dark:text-subtle">{{ $item['description'] }}</p>
                        </a>
                    @endforeach
                </div>
            </x-monitor::ui.panel>
        @endif

        <section aria-labelledby="workspace-summary" class="ui-panel px-6 py-6 sm:px-8 sm:py-7">
            <div class="relative flex flex-col justify-between gap-7 lg:flex-row lg:items-center">
                <div class="max-w-xl">
                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">Recorded activity</p>
                    <h2 id="workspace-summary" class="mt-3 text-xl font-bold tracking-tight sm:text-2xl">{{ $eventCount > 0 ? 'Explore '.number_format($eventCount).' '.Str::plural('event', $eventCount).' in this window.' : 'No telemetry in this window.' }}</h2>
                    <p class="mt-2 text-sm leading-6 text-muted">These are stored records, not a service health assessment. Sampling, missing instrumentation and retention affect coverage. No traffic does not establish uptime.</p>
                    <div class="mt-5 flex flex-wrap gap-3">
                        <a href="{{ route('monitor.events.index', ['range' => $range]) }}" class="ui-btn ui-btn-secondary">Explore events <x-monitor::icon name="arrow-up-right" class="h-4 w-4" /></a>
                        @if($canManageApplications)
                            <x-monitor::ui.button :href="route('monitor.settings.integrations')" class="gap-2" variant="secondary">View setup guide</x-monitor::ui.button>
                        @endif
                    </div>
                </div>
                <dl class="ui-card shadow-none flex shrink-0 flex-col gap-4 bg-surface-muted px-5 py-4 sm:px-6">
                    <div><dt class="text-xs text-muted">Unarchived applications</dt><dd class="mt-1 text-2xl font-bold">{{ number_format($applicationCount) }}</dd></div>
                    <div><dt class="text-xs text-muted">Environments with ingestion enabled</dt><dd class="mt-1 text-xl font-bold">{{ number_format($activeEnvironmentCount) }}</dd></div>
                </dl>
            </div>
        </section>

        @php
            $statCards = [
                [
                    'label' => 'Stored events', 'value' => number_format($eventCount), 'icon' => 'activity',
                    'change' => $changes['events'] === null ? 'No prior baseline' : ($changes['events'] > 0 ? '+' : '').number_format($changes['events'], 1).'% vs prior',
                    'caption' => 'By source event time, within the selected window',
                ],
                [
                    'label' => 'Average request duration', 'value' => \App\Modules\Monitor\Data\Telemetry\TraceRecord::formatDuration($averageDuration), 'icon' => 'clock',
                    'change' => $changes['duration'] === null ? 'No comparable baseline' : ($changes['duration'] > 0 ? '+' : '').number_format($changes['duration'], 1).'% vs prior',
                    'caption' => number_format($timedRequestCount).' of '.number_format($requestCount).' request records have a valid duration',
                ],
                [
                    'label' => 'Requests flagged as errors', 'value' => $requestErrorRate === null ? 'Not reported' : number_format($requestErrorRate, 2).'%', 'icon' => 'shield',
                    'change' => $changes['errorRate'] === null ? 'No comparable baseline' : ($changes['errorRate'] > 0 ? '+' : '').number_format($changes['errorRate'], 2).' pp vs prior',
                    'caption' => number_format($failedRequestCount).' of '.number_format($requestCount).' request records flagged',
                ],
                [
                    'label' => 'Open issues · all time', 'value' => number_format($openIssueCount), 'icon' => 'bug',
                    'change' => number_format($criticalIssueCount).' critical',
                    'tone' => $criticalIssueCount > 0 ? 'red' : 'slate',
                    'caption' => 'Current open issues across unarchived applications',
                ],
            ];
        @endphp
        <section aria-label="Telemetry metrics" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach($statCards as $stat)
                <x-monitor::ui.stat-card :label="$stat['label']" :value="$stat['value']" :icon="$stat['icon']" :change="$stat['change']" :tone="$stat['tone'] ?? 'slate'" :caption="$stat['caption']" />
            @endforeach
        </section>

        @php
            $collectionHealthItems = $collectionHealth['environments']->take(8);
            $collectionHealthAttention = $collectionHealth['total'] - $collectionHealth['counts']['receiving'];
        @endphp
        <x-monitor::ui.panel padding="p-0" :shadow="false" aria-labelledby="collection-health-heading">
            <div class="flex flex-col justify-between gap-4 border-b border-line p-5 sm:flex-row sm:items-start sm:p-6 dark:border-line">
                <div>
                    <h2 id="collection-health-heading" class="text-base font-bold">Collection health</h2>
                    <p class="mt-1 max-w-2xl text-xs leading-5 text-muted dark:text-subtle">Accepted telemetry freshness and active credential state across your environments. This is a collection signal, not an uptime assessment.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-monitor::ui.badge tone="green">{{ $collectionHealth['counts']['receiving'] }} receiving</x-monitor::ui.badge>
                    @if($collectionHealthAttention > 0)<x-monitor::ui.badge tone="amber">{{ $collectionHealthAttention }} need attention</x-monitor::ui.badge>@endif
                </div>
            </div>
            <div class="divide-y divide-line dark:divide-line">
                @forelse($collectionHealthItems as $health)
                    @php($environment = $health['environment'])
                    <a href="{{ route('monitor.environments.show', [$environment->application, $environment]) }}" class="flex flex-col justify-between gap-4 px-5 py-4 hover:bg-surface-muted sm:flex-row sm:items-center sm:px-6 dark:hover:bg-surface-muted">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2"><p class="text-sm font-semibold">{{ $environment->application->name }} / {{ $environment->name }}</p><x-monitor::ui.badge :tone="$health['state']->tone()">{{ $health['state']->label() }}</x-monitor::ui.badge></div>
                            <p class="mt-1 text-xs text-muted dark:text-subtle">{{ $health['description'] }}</p>
                        </div>
                        <div class="shrink-0 text-left text-xs text-muted sm:text-right dark:text-subtle"><p class="font-semibold text-ink dark:text-ink">{{ number_format($environment->event_count) }} events</p><p class="mt-1">{{ number_format((int) $environment->active_token_count) }} active {{ (int) $environment->active_token_count === 1 ? 'key' : 'keys' }}</p></div>
                    </a>
                @empty
                    <x-monitor::ui.empty-state class="m-5" icon="activity" title="No environments configured" description="Create an application to start receiving telemetry from your services.">
                        @if($canManageApplications)
                            <x-slot:action><a href="{{ route('monitor.applications.create') }}" class="mt-3 inline-block text-sm font-bold text-primary hover:underline dark:text-primary">Create an application →</a></x-slot:action>
                        @endif
                    </x-monitor::ui.empty-state>
                @endforelse
            </div>
            @if($collectionHealth['total'] > $collectionHealthItems->count() || $canManageApplications)
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-4 sm:px-6 dark:border-line">
                    <p class="text-xs text-muted dark:text-subtle">{{ $collectionHealth['total'] > $collectionHealthItems->count() ? 'Showing '.$collectionHealthItems->count().' of '.$collectionHealth['total'].' environments.' : 'Collection checks refresh when the overview is loaded.' }}</p>
                    @if($canManageApplications)<a href="{{ route('monitor.settings.integrations') }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">Integration setup →</a>@endif
                </div>
            @endif
        </x-monitor::ui.panel>

        <x-monitor::ui.accordion title="How these numbers are calculated">
            <div class="mt-3 flex flex-col gap-2">
                <p>Only telemetry from unarchived applications and environments is included. The window uses stored source timestamps, includes both endpoints, and excludes future-dated events.</p>
                <p>Prior window: {{ $previousFrom->format('Y-m-d H:i:s.u') }} UTC (inclusive) to {{ $from->format('Y-m-d H:i:s.u') }} UTC (exclusive). Percentage changes require a non-zero prior value; error-ratio changes use percentage points (pp).</p>
                <p>Previous: {{ number_format($previous['eventCount']) }} events · {{ number_format($previous['requestCount']) }} request records · {{ \App\Modules\Monitor\Data\Telemetry\TraceRecord::formatDuration($previous['averageDuration']) }} average duration · {{ $previous['requestErrorRate'] === null ? 'error ratio not reported' : number_format($previous['requestErrorRate'], 2).'% flagged as errors' }}.</p>
                <p>Duration averages include only request records with a non-negative reported duration. A request is flagged once if it has HTTP 5xx status or error/critical severity. Separate exception events are not included in that ratio. Request records may include internal or outbound calls; these metrics do not estimate unique incoming traffic or an SLO.</p>
            </div>
        </x-monitor::ui.accordion>

        <section class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1.6fr)_minmax(320px,0.8fr)]">
            <div class="ui-panel min-w-0 p-5 sm:p-6">
                <h2 class="text-base font-bold text-ink dark:text-ink">Request activity</h2>
                <p class="mt-1 text-xs leading-5 text-muted dark:text-subtle">{{ number_format($bucketMinutes / 60) }}-hour buckets · UTC · duration and volume use separate scales</p>
                @if($requestCount > 0)
                    <div class="mt-6 overflow-x-auto" tabindex="0" aria-label="Scrollable request charts; exact values are in the table below">
                        <div class="min-w-[480px]" aria-hidden="true">
                            <div class="flex items-center justify-between gap-3 text-[11px] text-muted dark:text-subtle"><span>Average request duration</span><span>{{ $timedRequestCount > 0 ? \App\Modules\Monitor\Data\Telemetry\TraceRecord::formatDuration($chartMaxDuration).' maximum bucket average' : 'Not reported' }}</span></div>
                            @if($timedRequestCount > 0)
                                <div class="mt-3 flex h-40 items-end gap-2 border-y border-dashed border-line dark:border-line">
                                    @foreach($trend as $point)
                                        <div class="relative flex h-full min-w-0 flex-1 items-end justify-center" title="{{ $point['from']->format('Y-m-d H:i:s') }} UTC: {{ \App\Modules\Monitor\Data\Telemetry\TraceRecord::formatDuration($point['averageDuration']) }}">
                                            @if($point['averageDuration'] === null)
                                                <span class="text-xs text-subtle">—</span>
                                            @else
                                                <span class="block min-h-px w-full max-w-10 rounded-t-sm bg-primary" style="height: {{ $chartMaxDuration > 0 ? $point['averageDuration'] / $chartMaxDuration * 100 : 0 }}%"></span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                <p class="mt-1 text-[10px] text-muted dark:text-subtle">0 ms baseline · dash = no timed requests; bars have a one-pixel minimum. Use the table for exact values.{{ $chartMaxDuration === 0.0 ? ' All reported durations are zero.' : '' }}</p>
                            @else
                                <p class="mt-3 rounded-control bg-surface-muted p-4 text-xs text-muted dark:bg-surface-muted dark:text-subtle">No valid request durations reported.</p>
                            @endif
                            <div class="mt-5 flex items-center justify-between gap-3 text-[11px] text-muted dark:text-subtle"><span>Request records</span><span>{{ number_format($chartMaxRequests) }} maximum per bucket</span></div>
                            <div class="mt-3 flex h-20 items-end gap-2 border-y border-dashed border-line dark:border-line">
                                @foreach($trend as $point)
                                    <div class="flex h-full min-w-0 flex-1 items-end justify-center" title="{{ $point['from']->format('Y-m-d H:i:s') }} UTC: {{ number_format($point['requestCount']) }} requests">
                                        <span class="block w-full max-w-10 rounded-t-sm bg-info" style="height: {{ $chartMaxRequests > 0 ? $point['requestCount'] / $chartMaxRequests * 100 : 0 }}%"></span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-2 flex gap-2">@foreach($trend as $point)<span class="min-w-0 flex-1 text-center text-[10px] text-muted dark:text-subtle">{{ $point['label'] }}</span>@endforeach</div>
                            <p class="mt-2 text-[10px] text-muted dark:text-subtle">Volume baseline: 0 records</p>
                        </div>
                    </div>
                @else
                    <div class="ui-card shadow-none mt-6 flex min-h-48 flex-col items-center justify-center gap-2 border-dashed p-5 text-center">
                        <x-monitor::icon name="activity" class="h-6 w-6 text-subtle" />
                        <p class="text-sm font-semibold">No request records in this window.</p>
                        <p class="text-xs text-muted dark:text-subtle">Other signal types still appear in the event mix.</p>
                    </div>
                @endif
                <x-monitor::ui.accordion title="View exact bucket values">
                    <p class="mt-3 leading-5 text-muted dark:text-subtle">Bucket starts are inclusive, ends exclusive; the final bucket includes the snapshot time. Missing durations are not plotted as zero.</p>
                    <div class="mt-3">
                        <x-monitor::ui.table caption="Stored request metrics by UTC time bucket" :framed="false">
                            <x-slot:head><tr><th scope="col" class="pr-4">UTC window</th><th scope="col" class="p-2 text-right">Requests</th><th scope="col" class="p-2 text-right">Timed</th><th scope="col" class="p-2 text-right">Flagged</th><th scope="col" class="pl-4 text-right">Average</th></tr></x-slot:head>
                                @foreach($trend as $point)
                                    <tr><th scope="row" class="whitespace-nowrap pr-4 text-[10px] font-normal">{{ $point['from']->format('Y-m-d H:i:s.u') }}<br>to {{ $point['until']->format('Y-m-d H:i:s.u') }}</th><td class="p-2 text-right">{{ number_format($point['requestCount']) }}</td><td class="p-2 text-right">{{ number_format($point['timedRequestCount']) }}</td><td class="p-2 text-right">{{ number_format($point['failedRequestCount']) }}</td><td class="whitespace-nowrap pl-4 text-right">{{ \App\Modules\Monitor\Data\Telemetry\TraceRecord::formatDuration($point['averageDuration']) }}</td></tr>
                                @endforeach
                        </x-monitor::ui.table>
                    </div>
                </x-monitor::ui.accordion>
            </div>

            <div class="ui-panel min-w-0 p-5 sm:p-6">
                <h2 class="text-base font-bold text-ink dark:text-ink">Event mix</h2>
                <p class="mt-1 text-xs text-muted dark:text-subtle">{{ number_format($eventCount) }} stored events in this window</p>
                @php($eventTypes = ['request' => 'Requests', 'query' => 'Queries', 'job' => 'Jobs', 'exception' => 'Exceptions', 'log' => 'Logs', 'metric' => 'Metrics', 'other' => 'Other event types'])
                <dl class="mt-6 flex flex-col gap-4">
                    @foreach($eventTypes as $type => $label)
                        @if($type !== 'other' || $eventBreakdown[$type] > 0)
                            <div>
                                <div class="flex items-center justify-between gap-3 text-xs"><dt class="font-semibold text-muted dark:text-muted">{{ $label }}</dt><dd class="text-right"><span class="font-bold text-ink dark:text-ink">{{ number_format($eventBreakdown[$type]) }}</span><span class="ml-2 text-muted dark:text-subtle">{{ $eventCount > 0 ? number_format($eventBreakdown[$type] / $eventCount * 100, 1).'%' : '—' }}</span></dd></div>
                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-surface-muted" aria-hidden="true"><div class="h-full rounded-full bg-primary" style="width: {{ $eventCount > 0 ? $eventBreakdown[$type] / $eventCount * 100 : 0 }}%"></div></div>
                            </div>
                        @endif
                    @endforeach
                </dl>
                <p class="mt-6 rounded-control bg-surface-muted p-3.5 text-xs leading-5 text-muted dark:bg-surface-muted dark:text-muted">{{ $eventCount === 0 ? 'No events match this window. Try a longer range or check your integration.' : 'Shares reflect record counts, not bytes or billed usage. Each metric data point is one record.' }}</p>
            </div>
        </section>

        <section class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
            <div class="ui-panel min-w-0 overflow-hidden">
                <div class="flex items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6 dark:border-line">
                    <div><h2 class="text-base font-bold">Applications</h2><p class="mt-1 text-xs text-muted dark:text-subtle">Showing {{ $applications->count() }} of {{ number_format($applicationCount) }} · inventory, not uptime</p></div>
                    <a href="{{ route('monitor.applications.index') }}" class="shrink-0 text-xs font-bold text-primary dark:text-primary">View all →</a>
                </div>
                <div class="divide-y divide-line dark:divide-line">
                    @forelse($applications as $application)
                        <div class="flex items-center gap-3 px-5 py-4 sm:px-6">
                            <x-monitor::ui.application-mark :application="$application" />
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('monitor.applications.show', $application) }}" class="block truncate text-sm font-bold hover:text-primary dark:hover:text-primary">{{ $application->name }}</a>
                                <p class="mt-1 truncate text-[11px] text-muted dark:text-subtle">{{ $application->framework }} {{ $application->framework_version }} · {{ $application->environments_count }} {{ Str::plural('environment', $application->environments_count) }}</p>
                                <p class="mt-1 text-[11px] text-muted dark:text-subtle">{{ $application->last_receipt_at ? 'Last receipt '.$application->last_receipt_at->diffForHumans() : 'No receipt recorded' }}</p>
                            </div>
                            <x-monitor::icon name="chevron-right" class="h-4 w-4 shrink-0 text-subtle" />
                        </div>
                    @empty
                        <p class="px-6 py-10 text-center text-sm text-muted dark:text-subtle">No applications configured.</p>
                    @endforelse
                </div>
            </div>
            <div class="ui-panel min-w-0 overflow-hidden">
                <div class="flex items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6 dark:border-line">
                    <div><h2 class="text-base font-bold">Open issues</h2><p class="mt-1 text-xs text-muted dark:text-subtle">{{ $openIssues->count() }} latest of {{ number_format($openIssueCount) }} · all time, not filtered by range</p></div>
                    <a href="{{ route('monitor.issues.index') }}" class="shrink-0 text-xs font-bold text-primary dark:text-primary">View all →</a>
                </div>
                <div class="divide-y divide-line dark:divide-line">
                    @forelse($openIssues as $issue)
                        <a href="{{ route('monitor.issues.show', $issue) }}" class="group flex items-center gap-3 px-5 py-4 hover:bg-surface-muted sm:px-6 dark:hover:bg-surface-muted">
                            <x-monitor::ui.badge :tone="match($issue->severity) { 'critical', 'error' => 'red', 'warning' => 'amber', default => 'slate' }">{{ $issue->severity }}</x-monitor::ui.badge>
                            <span class="min-w-0 flex-1"><span class="block truncate text-xs font-bold group-hover:text-primary dark:group-hover:text-primary">{{ $issue->title }}</span><span class="mt-1 block truncate text-[11px] text-muted dark:text-subtle">{{ $issue->application->name }} · {{ $issue->last_seen_at->diffForHumans() }}</span></span>
                            <span class="hidden shrink-0 text-right text-xs text-muted sm:block dark:text-subtle">{{ number_format($issue->occurrences) }}<br><span class="text-[10px]">occurrences</span></span>
                        </a>
                    @empty
                        <p class="px-6 py-10 text-center text-sm text-muted dark:text-subtle">No open issues recorded.</p>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="ui-panel overflow-hidden">
            <div class="flex flex-col justify-between gap-3 border-b border-line px-5 py-4 sm:flex-row sm:items-center sm:px-6 dark:border-line">
                <div><h2 class="text-base font-bold">Recent events</h2><p class="mt-1 text-xs text-muted dark:text-subtle">Latest {{ $latestEvents->count() }} in the selected window, ordered by source event time</p></div>
                <div class="flex items-center gap-2"><a href="{{ route('monitor.events.index', ['range' => $range]) }}" class="ui-btn ui-btn-secondary ui-btn-sm"><x-monitor::icon name="filter" class="h-3.5 w-3.5" />Filter</a><a href="{{ route('monitor.events.index', ['range' => $range]) }}" class="ui-btn ui-btn-secondary ui-btn-sm">View all <x-monitor::icon name="arrow-up-right" class="h-3.5 w-3.5" /></a></div>
            </div>
            <div class="divide-y divide-line dark:divide-line">
                @forelse($latestEvents as $event)
                    @php($eventTone = match($event->severity) { 'critical', 'error' => 'red', 'warning' => 'amber', default => 'slate' })
                    <div class="flex items-center gap-3 px-5 py-3.5 sm:px-6">
                        <x-monitor::ui.badge :tone="$eventTone">{{ $event->type }}</x-monitor::ui.badge>
                        <div class="min-w-0 flex-1"><a href="{{ route('monitor.events.show', $event->id) }}" class="block truncate text-xs font-bold hover:text-primary dark:hover:text-primary">{{ filled($event->name) ? $event->name : 'Unnamed '.$event->type }}</a><p class="mt-1 truncate text-[11px] text-muted dark:text-subtle">{{ $event->environment->application->name }} / {{ $event->environment->name }} · {{ $event->route ?? $event->service ?? 'system event' }}</p></div>
                        <span class="hidden shrink-0 text-right text-[11px] text-muted sm:block dark:text-subtle">{{ \App\Modules\Monitor\Data\Telemetry\TraceRecord::formatDuration($event->duration_ms !== null && $event->duration_ms >= 0 ? $event->duration_ms : null) }}<br><span class="text-[10px]">{{ $event->occurred_at->diffForHumans() }}</span></span>
                        @if(filled($event->trace_id))
                            <a href="{{ route('monitor.traces.show', $event->trace_id) }}" class="shrink-0 rounded-control p-1.5 text-subtle hover:bg-surface-muted hover:text-primary dark:hover:bg-surface-muted dark:hover:text-primary" aria-label="View trace"><x-monitor::icon name="arrow-up-right" class="h-4 w-4" /></a>
                        @endif
                    </div>
                @empty
                    <p class="px-6 py-10 text-center text-sm text-muted dark:text-subtle">No events match this window.</p>
                @endforelse
            </div>
        </section>
    </div>
@endsection
