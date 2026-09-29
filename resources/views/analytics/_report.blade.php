{{-- A site's report: headline numbers, the chart, ranked lists, goals and the live panel. Shared by the Analytics
     page and the public shared-report page; $reportUrl builds a link to the same report with other parameters, and
     $goalsUrl is null where goals can't be managed. --}}
@php($hourly = ($summary['granularity'] ?? 'day') === 'hour')
@php($chartLabel = $hourly ? __('Pageviews per hour') : __('Pageviews per day'))
@php($lists = $summary === null ? [] : [
    [__('Top pages'), $summary['pages'], __('Pages appear after the first visit.'), 'path'],
    [__('Entry pages'), $summary['entryPages'], __('Entry pages appear once visits are processed.'), 'path'],
    [__('Exit pages'), $summary['exitPages'], __('Exit pages appear once visits are processed.'), 'path'],
    [__('Sources'), $summary['sources'], __('Sources appear once visitors arrive.'), null],
    [__('Countries'), $summary['countries'], __('Countries appear once visitors arrive.'), 'country'],
    [__('Campaigns'), $summary['campaigns'], __('Campaigns appear after visits tagged with utm_campaign.'), null],
    [__('Devices'), $summary['devices'], __('Devices appear once visitors arrive.'), null],
    [__('Outbound links'), $summary['outboundLinks'], __('Add data-outbound to the snippet to count clicks on links to other sites.'), null],
    [__('File downloads'), $summary['fileDownloads'], __('Add data-downloads to the snippet to count file downloads.'), null],
    [__('Pages not found'), $summary['notFound'], __('Add data-not-found to the snippet on your 404 page to see which missing pages people reach.'), null],
])

    <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
        @foreach ($summary['metrics'] as $metric)
            <x-signal.ui.card class="p-4">
                <p class="text-xs font-bold text-muted">{{ __($metric['label']) }}</p>
                <p class="mt-2 text-2xl font-extrabold tracking-tight text-ink tabular-nums">{{ $metric['value'] }}</p>
                <p @class(['mt-1 text-xs', 'text-muted' => $metric['change'] === null, 'font-bold text-danger' => $metric['change'] !== null && str_starts_with($metric['change'], '-'), 'font-bold text-success' => $metric['change'] !== null && ! str_starts_with($metric['change'], '-')])>
                    {{ match (true) { $metric['change'] === null => __('This period'), $metric['change'] === 'New' => __('New this period'), $hourly => __(':change vs this time yesterday', ['change' => $metric['change']]), default => __(':change vs previous period', ['change' => $metric['change']]) } }}
                </p>
            </x-signal.ui.card>
        @endforeach
    </div>

    <x-signal.ui.card as="section" class="grid gap-4 p-5 sm:p-6" aria-labelledby="pageviews-heading">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="pageviews-heading" class="text-lg font-extrabold text-ink">{{ $chartLabel }}</h2>
            <p class="text-xs text-muted">{{ $hourly ? $summary['range']['start']->format('M j, Y') : $summary['range']['start']->format('M j').' – '.$summary['range']['end']->format('M j, Y') }} · {{ $site->timezone }}</p>
        </div>
        @if ($summary['hasData'])
            <x-signal.ui.bar-chart :label="$chartLabel" :points="array_map(fn (array $point): array => ['label' => $point['date'], 'value' => $point['value']], $summary['series'])" :unit="__('pageviews')"
                :markers="$releases->map(fn ($deployment): array => ['label' => $deployment->deployed_at->setTimezone($site->timezone)->format($hourly ? 'H:00' : 'M j'), 'text' => __('Released :version', ['version' => $deployment->release->version])])->values()->all()" />
        @else
            <p class="text-sm text-muted">{{ __('No pageviews in this period yet.') }}</p>
        @endif
        @if ($releases->isNotEmpty())
            <div class="mt-4 border-t border-line pt-4">
                <h3 class="text-sm font-extrabold text-ink">{{ __('Releases in this period') }}</h3>
                <ul class="mt-2 grid gap-1 text-xs text-muted">
                    @foreach ($releases as $deployment)
                        <li><span class="tabular-nums">{{ $deployment->deployed_at->setTimezone($site->timezone)->format('M j, H:i') }}</span> · <span class="font-mono text-ink">{{ $deployment->release->version }}</span> · {{ $deployment->environment->name }}@if ($deployment->source === 'deploy') · {{ __('Deploy') }}@endif</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-signal.ui.card>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($lists as [$title, $items, $empty, $filterKey])
            <x-signal.ui.card as="section" class="p-5" :aria-label="$title">
                <h2 class="font-extrabold text-ink">{{ $title }}</h2>
                <ul class="mt-4 grid gap-2.5 text-sm">
                    @forelse ($items as $item)
                        <li class="flex items-center justify-between gap-4">
                            @php($label = $filterKey === 'country' ? \App\Support\Country::label($item['label']) : $item['label'])
                            @if ($filterKey !== null && $item['label'] !== 'Unknown')
                                <a class="truncate text-muted hover:text-ink hover:underline" href="{{ $reportUrl(['days' => $days, ...array_filter($filters), $filterKey => $item['label']]) }}">{{ $label }}</a>
                            @else
                                <span class="truncate text-muted">{{ $label }}</span>
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
                @if ($goalsUrl)<a class="text-xs font-bold text-muted hover:text-ink" href="{{ $goalsUrl }}">{{ __('Manage goals') }}</a>@endif
            </div>
            <ul class="mt-4 grid gap-2.5 text-sm">
                @forelse ($summary['goals'] as $goal)
                    <li class="flex items-center justify-between gap-4"><span class="truncate text-muted">{{ $goal['name'] }}</span><strong class="tabular-nums text-ink">{{ number_format($goal['value']) }}</strong></li>
                @empty
                    <li class="text-muted">{{ __('Add a page or event goal to measure conversions.') }}</li>
                @endforelse
            </ul>
        </x-signal.ui.card>
        @include('analytics._live', ['recent' => $summary['recent']])
    </div>
