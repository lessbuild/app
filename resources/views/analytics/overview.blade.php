@php($project = $overview->project)
@php($lists = $summary === null ? [] : [
    [__('Top pages'), $summary['pages'], __('Pages appear after the first visit.'), true],
    [__('Entry pages'), $summary['entryPages'], __('Entry pages appear once visits are processed.'), true],
    [__('Exit pages'), $summary['exitPages'], __('Exit pages appear once visits are processed.'), true],
    [__('Sources'), $summary['sources'], __('Sources appear once visitors arrive.'), false],
    [__('Campaigns'), $summary['campaigns'], __('Campaigns appear after visits tagged with utm_campaign.'), false],
    [__('Devices'), $summary['devices'], __('Devices appear once visitors arrive.'), false],
])

<x-signal.layouts.project :overview="$overview" :title="__('Analytics')" :description="$site ? __(':site · cookieless visitor estimates, visits and goals', ['site' => $site->name]) : null">
    @if ($site === null)
        <x-signal.ui.empty-state icon="view-grid" :title="__('Add the website you want to understand')" :description="__('Create a site, add one small script, and your first pageview shows up here as soon as it’s processed.')">
            @if ($canManage)
                <x-slot:action><x-signal.ui.button :href="route('analytics.sites', $project)" variant="primary">{{ __('Add a site') }}</x-signal.ui.button></x-slot:action>
            @endif
        </x-signal.ui.empty-state>
    @else
        <x-signal.ui.card class="p-4 sm:p-5">
            <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[repeat(5,minmax(0,1fr))_auto] lg:items-end">
                @if (count($sites) > 1)
                    <x-signal.ui.select-field name="site" :label="__('Site')" :show-errors="false">
                        @foreach ($sites as $option)
                            <option value="{{ $option->id }}" @selected($site->id === $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                @else
                    <input type="hidden" name="site" value="{{ $site->id }}">
                @endif
                <x-signal.ui.select-field name="days" :label="__('Period')" :show-errors="false">
                    @foreach ([7 => __('Last 7 days'), 30 => __('Last 30 days'), 90 => __('Last 90 days'), 365 => __('Last 12 months')] as $value => $label)
                        <option value="{{ $value }}" @selected($days === $value)>{{ $label }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.input-field name="path" :label="__('Page')" :value="$filters['path']" placeholder="/pricing" :restore="false" :show-errors="false" />
                <x-signal.ui.input-field name="source" :label="__('Source')" :value="$filters['source']" :placeholder="__('newsletter or google.com')" :restore="false" :show-errors="false" />
                <x-signal.ui.select-field name="device" :label="__('Device')" :show-errors="false">
                    <option value="">{{ __('All devices') }}</option>
                    @foreach (['Desktop', 'Mobile', 'Tablet'] as $device)
                        <option value="{{ $device }}" @selected($filters['device'] === $device)>{{ $device }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <div class="flex gap-2">
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Apply') }}</x-signal.ui.button>
                    @if (array_filter($filters))
                        <x-signal.ui.button :href="route('analytics.overview', [$project, 'site' => $site->id, 'days' => $days])" variant="quiet">{{ __('Clear') }}</x-signal.ui.button>
                    @endif
                </div>
            </form>
        </x-signal.ui.card>

        @if (! $site->isVerified())
            <x-signal.ui.alert tone="warning" role="status"><strong>{{ $site->name }}</strong>&nbsp;{{ __('is waiting for verification before it collects.') }} <a class="ui-link" href="{{ route('analytics.sites.show', [$project, $site->id]) }}">{{ __('Finish setup') }}</a></x-signal.ui.alert>
        @elseif (! $site->isCollectionAvailable())
            <x-signal.ui.alert tone="warning" role="status">{{ __('Collection is paused for :site. Existing reports stay available.', ['site' => $site->name]) }}</x-signal.ui.alert>
        @endif

        <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
            @foreach ($summary['metrics'] as $metric)
                <x-signal.ui.card class="p-4">
                    <p class="text-xs font-bold text-muted">{{ __($metric['label']) }}</p>
                    <p class="mt-2 text-2xl font-extrabold tracking-tight text-ink tabular-nums">{{ $metric['value'] }}</p>
                    <p @class(['mt-1 text-xs', 'text-muted' => $metric['change'] === null, 'font-bold text-danger' => $metric['change'] !== null && str_starts_with($metric['change'], '-'), 'font-bold text-success' => $metric['change'] !== null && ! str_starts_with($metric['change'], '-')])>
                        {{ match (true) { $metric['change'] === null => __('This period'), $metric['change'] === 'New' => __('New this period'), default => __(':change vs previous period', ['change' => $metric['change']]) } }}
                    </p>
                </x-signal.ui.card>
            @endforeach
        </div>

        <x-signal.ui.card as="section" class="grid gap-4 p-5 sm:p-6" aria-labelledby="pageviews-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="pageviews-heading" class="text-lg font-extrabold text-ink">{{ __('Pageviews per day') }}</h2>
                <p class="text-xs text-muted">{{ $summary['range']['start']->format('M j') }} – {{ $summary['range']['end']->format('M j, Y') }} · {{ $site->timezone }}</p>
            </div>
            @if ($summary['hasData'])
                <x-signal.ui.bar-chart :label="__('Pageviews per day')" :points="array_map(fn (array $point): array => ['label' => $point['date'], 'value' => $point['value']], $summary['series'])" :unit="__('pageviews')" />
            @else
                <p class="text-sm text-muted">{{ __('No pageviews in this period yet.') }}</p>
            @endif
        </x-signal.ui.card>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($lists as [$title, $items, $empty, $linksToPage])
                <x-signal.ui.card as="section" class="p-5" :aria-label="$title">
                    <h2 class="font-extrabold text-ink">{{ $title }}</h2>
                    <ul class="mt-4 grid gap-2.5 text-sm">
                        @forelse ($items as $item)
                            <li class="flex items-center justify-between gap-4">
                                @if ($linksToPage)
                                    <a class="truncate text-muted hover:text-ink hover:underline" href="{{ route('analytics.overview', [$project, 'site' => $site->id, 'days' => $days, 'path' => $item['label']]) }}">{{ $item['label'] }}</a>
                                @else
                                    <span class="truncate text-muted">{{ $item['label'] }}</span>
                                @endif
                                <strong class="tabular-nums text-ink">{{ number_format($item['value']) }}</strong>
                            </li>
                        @empty
                            <li class="text-muted">{{ $empty }}</li>
                        @endforelse
                    </ul>
                </x-signal.ui.card>
            @endforeach
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-signal.ui.card as="section" class="p-5" aria-labelledby="goals-heading">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="goals-heading" class="font-extrabold text-ink">{{ __('Goals') }}</h2>
                    <a class="text-xs font-bold text-muted hover:text-ink" href="{{ route('analytics.goals', [$project, 'site' => $site->id]) }}">{{ __('Manage goals') }}</a>
                </div>
                <ul class="mt-4 grid gap-2.5 text-sm">
                    @forelse ($summary['goals'] as $goal)
                        <li class="flex items-center justify-between gap-4"><span class="truncate text-muted">{{ $goal['name'] }}</span><strong class="tabular-nums text-ink">{{ number_format($goal['value']) }}</strong></li>
                    @empty
                        <li class="text-muted">{{ __('Add a page or event goal to measure conversions.') }}</li>
                    @endforelse
                </ul>
            </x-signal.ui.card>
            <x-signal.ui.card as="section" class="p-5" aria-labelledby="live-heading">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="live-heading" class="font-extrabold text-ink">{{ __('Last 5 minutes') }}</h2>
                    <span class="text-xs text-muted">{{ trans_choice(':count visitor|:count visitors', $summary['recent']['visitorCount'], ['count' => $summary['recent']['visitorCount']]) }}</span>
                </div>
                <ul class="mt-3 divide-y divide-line text-sm">
                    @forelse ($summary['recent']['events'] as $event)
                        <li class="flex items-center justify-between gap-4 py-2.5">
                            <span class="min-w-0"><span class="block truncate font-bold text-ink">{{ $event['path'] }}</span><span class="text-xs text-muted">{{ ucfirst($event['type']) }} · {{ $event['source'] }}</span></span>
                            <time class="shrink-0 text-xs text-muted" datetime="{{ $event['occurredAt']->toIso8601String() }}">{{ $event['occurredAt']->diffForHumans() }}</time>
                        </li>
                    @empty
                        <li class="py-2.5 text-muted">{{ __('No traffic in the last five minutes.') }}</li>
                    @endforelse
                </ul>
            </x-signal.ui.card>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-muted">
            <p>{{ __('Visitors are cookieless daily estimates; visits end after 30 minutes without activity. Raw detail is kept :days days.', ['days' => config('analytics.event_retention_days')]) }} {{ __('Last processed :time.', ['time' => $summary['lastProcessedAt']?->diffForHumans() ?? __('never')]) }}</p>
            <form method="POST" action="{{ route('analytics.exports.store', [$project, $site->id]) }}">
                @csrf
                <input type="hidden" name="days" value="{{ $days }}">
                @foreach ($filters as $key => $value)
                    @if ($value !== null)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                @endforeach
                <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Export CSV') }}</x-signal.ui.button>
            </form>
        </div>
    @endif
</x-signal.layouts.project>
