<div class="space-y-8">
    <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
        <div>
            <p class="ui-eyebrow">Website analytics</p>
            <h2 class="mt-2 text-3xl font-extrabold tracking-tight text-ink">A clear read on your traffic.</h2>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Useful trends from the sites you care about, with cookieless visitor estimates and explicit attribution.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <select class="ui-input w-auto min-w-44" wire:model.live="selectedSiteId" aria-label="Choose website">
                @forelse ($sites as $availableSite)
                    <option value="{{ $availableSite->id }}">{{ $availableSite->name }}</option>
                @empty
                    <option value="">No websites yet</option>
                @endforelse
            </select>
            <select class="ui-input w-auto" wire:model.live="days" aria-label="Report date range">
                <option value="7">Last 7 days</option>
                <option value="30">Last 30 days</option>
                <option value="90">Last 90 days</option>
                <option value="365">Last 12 months</option>
            </select>
            @if ($site)
                <a class="ui-btn ui-btn-secondary" href="{{ route('analytics.sites.settings', $site) }}">Settings</a>
            @endif
        </div>
    </div>

    @if (session('status'))<div class="flex flex-wrap items-center justify-between gap-3 rounded-panel border border-success/30 bg-success-soft px-5 py-4 text-sm text-success"><span>{{ session('status') }}</span>@if (session('export_token'))<a class="font-bold underline" href="{{ route('analytics.reports.exports.show', session('export_token')) }}">View export status →</a>@endif</div>@endif

    @if (! $site)
        <section class="ui-panel overflow-hidden">
            <div class="grid gap-8 p-6 sm:p-10 lg:grid-cols-[1fr_0.8fr] lg:items-center">
                <div>
                    <span class="inline-flex rounded-full bg-primary-soft px-3 py-1 text-xs font-extrabold text-ink">First step</span>
                    <h3 class="mt-5 text-2xl font-extrabold tracking-tight">Add the website you want to understand.</h3>
                    <p class="mt-3 max-w-xl text-sm leading-6 text-muted">Create a site, verify its domain, and add one small script. Your first pageview will appear here as soon as it is processed.</p>
                    <a class="ui-btn ui-btn-primary mt-6" href="{{ route('analytics.sites.create') }}">Add a website <span aria-hidden="true">→</span></a>
                </div>
                <div class="rounded-panel bg-surface-muted p-6">
                    <p class="ui-eyebrow">What you’ll see</p>
                    <div class="mt-5 space-y-4 text-sm">
                        <div class="flex gap-3"><span class="grid size-7 shrink-0 place-items-center rounded-full bg-surface text-xs font-extrabold">1</span><p class="text-muted"><strong class="text-ink">Visitors and visits</strong><br>Daily estimates and session activity without cross-site tracking.</p></div>
                        <div class="flex gap-3"><span class="grid size-7 shrink-0 place-items-center rounded-full bg-surface text-xs font-extrabold">2</span><p class="text-muted"><strong class="text-ink">Pages and sources</strong><br>See what brings people in and where they go.</p></div>
                        <div class="flex gap-3"><span class="grid size-7 shrink-0 place-items-center rounded-full bg-surface text-xs font-extrabold">3</span><p class="text-muted"><strong class="text-ink">Goals</strong><br>Measure the actions that matter to your team.</p></div>
                    </div>
                </div>
            </div>
        </section>
    @else
        <div class="ui-panel grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-5">
            <div><label class="ui-label" for="filter-path">Page</label><input class="ui-input" id="filter-path" wire:model.live="pathFilter" placeholder="/pricing"></div>
            <div><label class="ui-label" for="filter-source">Source</label><input class="ui-input" id="filter-source" wire:model.live="sourceFilter" placeholder="newsletter or google.com"></div>
            <div><label class="ui-label" for="filter-campaign">Campaign</label><input class="ui-input" id="filter-campaign" wire:model.live="campaignFilter" placeholder="launch"></div>
            <div><label class="ui-label" for="filter-device">Device</label><select class="ui-input" id="filter-device" wire:model.live="deviceFilter"><option value="">All devices</option><option value="Desktop">Desktop</option><option value="Mobile">Mobile</option><option value="Tablet">Tablet</option></select></div>
            <div class="flex items-end gap-2"><button class="ui-btn ui-btn-secondary flex-1" type="button" wire:click="clearFilters">Clear</button><form method="POST" action="{{ route('analytics.reports.exports.store', $site) }}" class="flex-1">@csrf<input type="hidden" name="days" value="{{ $days }}"><input type="hidden" name="path" value="{{ $pathFilter }}"><input type="hidden" name="source" value="{{ $sourceFilter }}"><input type="hidden" name="campaign" value="{{ $campaignFilter }}"><input type="hidden" name="device" value="{{ $deviceFilter }}"><button class="ui-btn ui-btn-primary w-full" type="submit">Export CSV</button></form></div>
        </div>
        @if (! $site->isVerified())
            <div class="flex flex-col justify-between gap-3 rounded-panel border border-warning/30 bg-warning-soft px-5 py-4 text-sm text-warning sm:flex-row sm:items-center"><p><strong>{{ $site->name }}</strong> is waiting for domain verification before collection can begin.</p><a class="ui-btn ui-btn-secondary !border-warning/30 !bg-transparent !text-warning" href="{{ route('analytics.sites.setup', $site) }}">Finish setup →</a></div>
        @elseif (! $site->isCollectionAvailable())
            <div class="flex flex-col justify-between gap-3 rounded-panel border border-warning/30 bg-warning-soft px-5 py-4 text-sm text-warning sm:flex-row sm:items-center"><p><strong>{{ $site->name }}</strong> collection is paused. Existing reports remain available.</p><a class="ui-btn ui-btn-secondary !border-warning/30 !bg-transparent !text-warning" href="{{ route('analytics.sites.settings', $site) }}">Review settings →</a></div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
            @foreach ($summary['metrics'] as $metric)
                <article class="ui-panel p-5">
                    <div class="flex items-center justify-between"><p class="text-xs font-bold uppercase tracking-wider text-muted">{{ $metric['label'] }}</p><span class="size-2 rounded-full @if($metric['tone'] === 'success') bg-success @elseif($metric['tone'] === 'warning') bg-warning @elseif($metric['tone'] === 'info') bg-info @else bg-primary @endif"></span></div>
                    <p class="metric-value mt-4">{{ $metric['value'] }}</p>
                    @if ($metric['change'])<p class="mt-2 text-xs font-bold @if(str_starts_with($metric['change'], '-')) text-danger @else text-success @endif">{{ $metric['change'] }} <span class="font-normal text-muted">vs previous period</span></p>@else<p class="mt-2 text-xs text-muted">Current reporting period</p>@endif
                </article>
            @endforeach
        </div>

        <section class="ui-panel p-5 sm:p-7">
            <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-start"><div><p class="ui-eyebrow">Traffic trend</p><h3 class="mt-2 text-xl font-extrabold">Pageviews over time</h3></div><p class="text-xs text-muted">{{ $summary['range']['start']->format('M j') }} – {{ $summary['range']['end']->format('M j, Y') }}</p></div>
            <div class="mt-8" role="img" aria-label="Pageviews by day chart"><div class="flex h-48 items-end gap-1 border-b border-l border-line px-2 pb-0 sm:gap-2">@php($max = max(1, max(array_column($summary['series'], 'value')))) @foreach ($summary['series'] as $point)<div class="group flex h-full flex-1 flex-col justify-end"><div class="mx-auto w-full max-w-8 rounded-t bg-primary transition hover:bg-primary-hover" style="height: {{ max(4, ($point['value'] / $max) * 100) }}%" title="{{ $point['date'] }}: {{ number_format($point['value']) }} pageviews"></div></div>@endforeach</div><div class="mt-3 flex justify-between gap-1 px-2 text-[0.65rem] text-subtle"><span>{{ $summary['series'][0]['date'] ?? '' }}</span><span>{{ $summary['series'][floor(count($summary['series']) / 2)]['date'] ?? '' }}</span><span>{{ $summary['series'][count($summary['series']) - 1]['date'] ?? '' }}</span></div></div>
            <details class="mt-5"><summary class="cursor-pointer text-xs font-bold text-muted">View chart data</summary><div class="mt-3 overflow-x-auto"><table class="w-full text-left text-xs"><thead><tr class="text-muted"><th class="pb-2 pr-4">Date</th><th class="pb-2">Pageviews</th></tr></thead><tbody>@foreach ($summary['series'] as $point)<tr class="table-row"><td class="py-2 pr-4">{{ $point['date'] }}</td><td class="py-2">{{ number_format($point['value']) }}</td></tr>@endforeach</tbody></table></div></details>
        </section>

        <section aria-labelledby="release-annotations-heading">
            <x-signal.ui.card class="overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line p-5">
                <div>
                    <p class="ui-eyebrow">Shared project activity</p>
                    <h3 id="release-annotations-heading" class="mt-2 text-lg font-extrabold">Recent releases</h3>
                    <p class="mt-1 text-xs text-muted">Deployer releases connected to this Analytics site.</p>
                </div>
                <x-signal.ui.badge tone="neutral">{{ trans_choice(':count recent annotation|:count recent annotations', $releaseAnnotations->count(), ['count' => $releaseAnnotations->count()]) }}</x-signal.ui.badge>
            </div>
            <div class="divide-y divide-line px-5">
                @forelse ($releaseAnnotations as $release)
                    <article class="flex flex-wrap items-center justify-between gap-3 py-4">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-ink">{{ $release->version }}</p>
                            @if ($release->revision)
                                <p class="mt-1 font-mono text-xs text-muted">{{ substr($release->revision, 0, 12) }}</p>
                            @endif
                        </div>
                        <time class="shrink-0 text-xs text-muted" datetime="{{ $release->deployed_at->toIso8601String() }}">{{ $release->deployed_at->diffForHumans() }}</time>
                    </article>
                @empty
                    <p class="py-5 text-sm text-muted">No connected releases yet. Connect a Deployer environment from the shared project to add release context here.</p>
                @endforelse
            </div>
            </x-signal.ui.card>
        </section>

        <div class="grid gap-5 xl:grid-cols-5">
            @foreach ([['title' => 'Top pages', 'eyebrow' => 'Content', 'items' => $summary['pages'], 'empty' => 'Pageviews will appear after your first visit.'], ['title' => 'Entry pages', 'eyebrow' => 'Visits', 'items' => $summary['entryPages'], 'empty' => 'Entry paths appear after visits are processed.'], ['title' => 'Exit pages', 'eyebrow' => 'Visits', 'items' => $summary['exitPages'], 'empty' => 'Exit paths appear after visits are processed.'], ['title' => 'Top sources', 'eyebrow' => 'Acquisition', 'items' => $summary['sources'], 'empty' => 'Sources will appear after collection starts.'], ['title' => 'Campaigns', 'eyebrow' => 'Acquisition', 'items' => $summary['campaigns'], 'empty' => 'Campaign values will appear after tagged visits.']] as $section)
                <section class="ui-panel p-5"><p class="ui-eyebrow">{{ $section['eyebrow'] }}</p><h3 class="mt-2 text-lg font-extrabold">{{ $section['title'] }}</h3><div class="mt-5 space-y-3">@forelse ($section['items'] as $item)<div class="flex items-center justify-between gap-4 text-sm">@if (in_array($section['title'], ['Top pages', 'Entry pages', 'Exit pages'], true))<a class="truncate text-muted underline decoration-line hover:text-ink" href="{{ route('analytics.dashboard', ['path' => $item['label']]) }}">{{ $item['label'] }}</a>@else<span class="truncate text-muted">{{ $item['label'] }}</span>@endif<strong class="text-ink">{{ number_format($item['value']) }}</strong></div>@empty<p class="text-sm text-muted">{{ $section['empty'] }}</p>@endforelse</div></section>
            @endforeach
        </div>

        <div class="grid gap-5 lg:grid-cols-[1.1fr_0.9fr]">
            <section class="ui-panel p-5" wire:poll.30s.visible>
                <div class="flex items-center justify-between"><div><p class="ui-eyebrow">Live signal</p><h3 class="mt-2 text-lg font-extrabold">Recent traffic</h3></div><span class="text-xs text-muted">Last 5 minutes</span></div>
                <div class="mt-5 flex items-end gap-4"><p class="text-3xl font-extrabold text-ink">{{ number_format($summary['recent']['visitorCount']) }}</p><p class="pb-1 text-sm text-muted">visitors seen recently</p></div>
                <div class="mt-5 divide-y divide-line">@forelse ($summary['recent']['events'] as $event)<div class="flex items-center justify-between gap-4 py-3 text-sm"><div class="min-w-0"><p class="truncate font-semibold text-ink">{{ $event['path'] }}</p><p class="text-xs text-muted">{{ ucfirst($event['type']) }} · {{ $event['source'] }}</p></div><time class="shrink-0 text-xs text-subtle" datetime="{{ $event['occurredAt']->toIso8601String() }}">{{ $event['occurredAt']->diffForHumans() }}</time></div>@empty<p class="py-3 text-sm text-muted">No traffic has arrived in the last five minutes.</p>@endforelse</div>
            </section>
            <section class="ui-panel p-5"><div class="flex items-center justify-between"><div><p class="ui-eyebrow">Goals</p><h3 class="mt-2 text-lg font-extrabold">Conversion signals</h3></div><a class="text-xs font-bold text-muted hover:text-ink" href="{{ route('analytics.goals.index', $site) }}">Manage goals →</a></div><div class="mt-5 space-y-3">@forelse ($summary['goals'] as $goal)<div class="flex items-center justify-between gap-4 text-sm"><span class="truncate text-muted">{{ $goal['name'] }}</span><strong class="text-ink">{{ number_format($goal['value']) }}</strong></div>@empty<p class="text-sm text-muted">Add a path or event goal to measure conversions.</p>@endforelse</div></section>
        </div>

        <div class="grid gap-5 md:grid-cols-3"><section class="ui-panel p-5"><p class="ui-eyebrow">Audience</p><h3 class="mt-2 text-lg font-extrabold">Devices</h3><div class="mt-5 space-y-3">@forelse ($summary['devices'] as $item)<div class="flex items-center justify-between gap-4 text-sm"><span class="truncate text-muted">{{ $item['label'] }}</span><strong class="text-ink">{{ number_format($item['value']) }}</strong></div>@empty<p class="text-sm text-muted">Device categories will appear here.</p>@endforelse</div></section><section class="ui-panel p-5"><p class="ui-eyebrow">Audience</p><h3 class="mt-2 text-lg font-extrabold">Browsers & OS</h3><div class="mt-5 space-y-2 text-sm">@foreach (array_slice($summary['browsers'], 0, 3) as $item)<div class="flex justify-between gap-3"><span class="truncate text-muted">{{ $item['label'] }}</span><strong>{{ number_format($item['value']) }}</strong></div>@endforeach @foreach (array_slice($summary['operatingSystems'], 0, 3) as $item)<div class="flex justify-between gap-3"><span class="truncate text-muted">{{ $item['label'] }}</span><strong>{{ number_format($item['value']) }}</strong></div>@endforeach</div></section><section class="ui-panel p-5"><p class="ui-eyebrow">Methodology</p><h3 class="mt-2 text-lg font-extrabold">How to read this report</h3><p class="mt-4 text-sm leading-6 text-muted">Visitors are cookieless daily estimates. Visits use a 30-minute inactivity window, and attribution gives explicit UTM values priority over external referrers. Report timezone: {{ $site->timezone }}.</p></section></div>
        <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-muted"><p>Raw detail is retained for {{ config('analytics.event_retention_days') }} days.</p><p>Last processed: {{ $summary['lastProcessedAt']?->diffForHumans() ?? 'No processed events yet' }}</p></div>
    @endif
</div>
