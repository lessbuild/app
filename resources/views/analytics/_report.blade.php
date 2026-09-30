{{-- A site's report: headline numbers, the chart, ranked lists, goals and the live panel. Shared by the Analytics
     page and the public shared-report page; $reportUrl builds a link to the same report with other parameters, and
     $goalsUrl is null where goals can't be managed. --}}
@php($hourly = ($summary['granularity'] ?? 'day') === 'hour')
@php($chartLabel = $hourly ? __('Pageviews per hour') : __('Pageviews per day'))
@php($lists = $summary === null ? [] : [
    [__('Top pages'), $summary['pages'], __('Pages appear after the first visit.'), 'path'],
    [__('Content groups'), $summary['contentGroups'] ?? [], '', 'group'],
    [__('Entry pages'), $summary['entryPages'], __('Entry pages appear once visits are processed.'), 'path'],
    [__('Exit pages'), $summary['exitPages'], __('Exit pages appear once visits are processed.'), 'path'],
    [__('Channels'), $summary['channels'] ?? [], __('Channels appear once visitors arrive.'), 'channel'],
    [__('Sources'), $summary['sources'], __('Sources appear once visitors arrive.'), null],
    [__('Countries'), $summary['countries'], __('Countries appear once visitors arrive.'), 'country'],
    [__('Regions'), $summary['regions'] ?? [], '', 'region'],
    [__('Cities'), $summary['cities'] ?? [], '', 'city'],
    [__('Campaigns'), $summary['campaigns'], __('Campaigns appear after visits tagged with utm_campaign.'), 'campaign'],
    [__('Campaign terms'), $summary['terms'] ?? [], '', 'term'],
    [__('Campaign content'), $summary['contents'] ?? [], '', 'content'],
    [__('Devices'), $summary['devices'], __('Devices appear once visitors arrive.'), 'device'],
    [__('Browsers'), $summary['browsers'], __('Browsers appear once visitors arrive.'), 'browser'],
    [__('Operating systems'), $summary['operatingSystems'], __('Operating systems appear once visitors arrive.'), 'os'],
    [__('Screen sizes'), $summary['screenSizes'] ?? [], '', 'screen'],
    [__('Browser versions'), $summary['browserVersions'] ?? [], '', null],
    [__('System versions'), $summary['osVersions'] ?? [], '', null],
    [__('Outbound links'), $summary['outboundLinks'], '', null],
    [__('File downloads'), $summary['fileDownloads'], '', null],
    [__('Pages not found'), $summary['notFound'], '', null],
    [__('Site searches'), $summary['searches'] ?? [], '', null],
    [__('Searches with no results'), $summary['emptySearches'] ?? [], '', null],
])
{{-- The tracker's opt-in lists only show once they have something in them; the site page explains how to switch them on. --}}
@php($lists = array_values(array_filter($lists, fn (array $list): bool => $list[2] !== '' || $list[1] !== [])))

    <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
        @foreach ($summary['metrics'] as $metric)
            <x-signal.ui.card class="p-4">
                <p class="text-xs font-bold text-muted">{{ __($metric['label']) }}</p>
                <p class="mt-2 text-2xl font-extrabold tracking-tight text-ink tabular-nums">{{ $metric['value'] }}</p>
                <p @class(['mt-1 text-xs', 'text-muted' => $metric['change'] === null, 'font-bold text-danger' => $metric['change'] !== null && str_starts_with($metric['change'], '-'), 'font-bold text-success' => $metric['change'] !== null && ! str_starts_with($metric['change'], '-')])>
                    {{ match (true) { $metric['change'] === null => __('This period'), $metric['change'] === 'New' => __('New this period'), $summary['period']->compare === 'year' => __(':change vs last year', ['change' => $metric['change']]), $hourly => __(':change vs the day before', ['change' => $metric['change']]), default => __(':change vs previous period', ['change' => $metric['change']]) } }}
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
                :markers="[...$releases->map(fn ($deployment): array => ['label' => $deployment->deployed_at->setTimezone($site->timezone)->format($hourly ? 'H:00' : 'M j'), 'text' => __('Released :version', ['version' => $deployment->release->version])])->values()->all(), ...($hourly ? [] : ($annotations ?? collect())->map(fn ($note): array => ['label' => $note->date->format('M j'), 'text' => $note->text])->values()->all())]" />
        @else
            <p class="text-sm text-muted">{{ __('No pageviews in this period yet.') }}</p>
        @endif
        @if (($annotations ?? collect())->isNotEmpty())
            <div class="mt-4 border-t border-line pt-4">
                <h3 class="text-sm font-extrabold text-ink">{{ __('Notes') }}</h3>
                <ul class="mt-2 grid gap-1 text-xs text-muted">
                    @foreach ($annotations as $note)
                        <li class="flex flex-wrap items-center gap-2">
                            <span class="tabular-nums">{{ $note->date->format('M j') }}</span> · <span class="text-ink">{{ $note->text }}</span>
                            @if ($canManage ?? false)
                                <form method="POST" action="{{ route('analytics.annotations.destroy', [$site->project_id, $site->id, $note->id]) }}">@csrf @method('DELETE')<button type="submit" class="ui-link text-xs">{{ __('Remove') }}</button></form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
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

    @php($vitals = $summary['vitals'] ?? ['samples' => 0, 'metrics' => [], 'slowPages' => []])
    @php($vitalNames = ['lcp' => [__('Largest Contentful Paint'), __('How long the main content takes to appear')], 'inp' => [__('Interaction to Next Paint'), __('How quickly the page responds to taps and clicks')], 'cls' => [__('Cumulative Layout Shift'), __('How much the page jumps around while loading')], 'ttfb' => [__('Time to First Byte'), __('How long the server takes to answer')]])
    @php($ratingLabels = ['good' => [__('Good'), 'success'], 'needs_improvement' => [__('Needs improvement'), 'warning'], 'poor' => [__('Poor'), 'danger']])
    @php($formatVital = fn (string $metric, float $value): string => $metric === 'cls' ? number_format($value, 2) : ($value >= 1000 ? number_format($value / 1000, 2).' s' : number_format($value).' ms'))
    <x-signal.ui.card as="section" class="grid gap-4 p-5 sm:p-6" aria-labelledby="speed-heading">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="speed-heading" class="text-lg font-extrabold text-ink">{{ __('Page speed') }}</h2>
            <p class="text-xs text-muted">{{ $vitals['samples'] > 0 ? trans_choice('75th percentile of :count page load by real visitors|75th percentile of :count page loads by real visitors', $vitals['samples'], ['count' => number_format($vitals['samples'])]) : __('Core Web Vitals from real visitors') }}</p>
        </div>
        @if ($vitals['samples'] === 0)
            <p class="text-sm text-muted">{{ __('Add data-vitals to the snippet to measure how fast pages load for real visitors, and see whether a release made them slower.') }}</p>
        @else
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($vitalNames as $metric => [$name, $explanation])
                    @php($measure = $vitals['metrics'][$metric] ?? ['value' => null, 'rating' => null])
                    <div class="rounded-control border border-line p-4">
                        <p class="text-xs font-bold text-muted">{{ $name }} <span class="uppercase">({{ $metric }})</span></p>
                        <p class="mt-2 text-2xl font-extrabold tracking-tight text-ink tabular-nums">{{ $measure['value'] === null ? '—' : $formatVital($metric, $measure['value']) }}</p>
                        @if ($measure['rating'])<x-signal.ui.badge class="mt-2" :tone="$ratingLabels[$measure['rating']][1]">{{ $ratingLabels[$measure['rating']][0] }}</x-signal.ui.badge>@endif
                        <p class="mt-2 text-xs text-muted">{{ $explanation }}</p>
                    </div>
                @endforeach
            </div>
            @if ($vitals['slowPages'] !== [])
                <div>
                    <h3 class="text-sm font-extrabold text-ink">{{ __('Slowest pages to load') }}</h3>
                    <ul class="mt-2 grid gap-2 text-sm">
                        @foreach ($vitals['slowPages'] as $page)
                            <li class="flex items-center justify-between gap-4">
                                <a class="truncate text-muted hover:text-ink hover:underline" href="{{ $reportUrl([...$summary['period']->query(), ...array_filter($filters), 'path' => $page['path']]) }}">{{ $page['path'] }}</a>
                                <span class="shrink-0 tabular-nums"><strong class="text-ink">{{ $formatVital('lcp', $page['lcp']) }}</strong> <span class="text-xs text-muted">· {{ trans_choice(':count load|:count loads', $page['samples'], ['count' => $page['samples']]) }}</span></span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
    </x-signal.ui.card>

    @if ($summary['searchTerms'] ?? null)
        @php($terms = $summary['searchTerms'])
        <x-signal.ui.card as="section" class="grid gap-4 p-5 sm:p-6" aria-labelledby="search-terms-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="search-terms-heading" class="text-lg font-extrabold text-ink">{{ __('Google search terms') }}</h2>
                <p class="text-xs text-muted">{{ __('From Google Search Console (:property). Google’s figures lag by about two days.', ['property' => $terms['property']]) }}</p>
            </div>
            @if ($terms['error'])
                <x-signal.ui.alert tone="warning">{{ $terms['error'] }}</x-signal.ui.alert>
            @elseif ($terms['rows'] === [])
                <p class="text-sm text-muted">{{ __('No searches led here in this period.') }}</p>
            @else
                <x-signal.ui.table :caption="__('Google search terms')" :framed="false">
                    <x-slot:head><tr><th scope="col">{{ __('Search term') }}</th><th scope="col" class="text-right">{{ __('Clicks') }}</th><th scope="col" class="text-right">{{ __('Impressions') }}</th><th scope="col" class="text-right">{{ __('Click rate') }}</th><th scope="col" class="text-right">{{ __('Position') }}</th></tr></x-slot:head>
                    @foreach ($terms['rows'] as $row)
                        <tr>
                            <td class="font-bold text-ink">{{ $row['query'] }}</td>
                            <td class="text-right tabular-nums">{{ number_format($row['clicks']) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($row['impressions']) }}</td>
                            <td class="text-right tabular-nums">{{ $row['ctr'] }}%</td>
                            <td class="text-right tabular-nums">{{ $row['position'] }}</td>
                        </tr>
                    @endforeach
                </x-signal.ui.table>
            @endif
        </x-signal.ui.card>
    @endif

    @php($engagement = array_values(array_filter($summary['engagement'] ?? [], fn (array $row): bool => $row['seconds'] !== null || $row['scroll'] !== null)))
    @if ($engagement !== [])
        <x-signal.ui.card as="section" class="grid gap-4 p-5 sm:p-6" aria-labelledby="engagement-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="engagement-heading" class="text-lg font-extrabold text-ink">{{ __('Engagement') }}</h2>
                <p class="text-xs text-muted">{{ __('Time the page was visible, and how far down visitors scrolled.') }}</p>
            </div>
            <x-signal.ui.table :caption="__('Engagement by page')" :framed="false">
                <x-slot:head><tr><th scope="col">{{ __('Page') }}</th><th scope="col" class="text-right">{{ __('Pageviews') }}</th><th scope="col" class="text-right">{{ __('Time on page') }}</th><th scope="col" class="text-right">{{ __('Scroll depth') }}</th></tr></x-slot:head>
                @foreach ($engagement as $row)
                    <tr>
                        <td class="max-w-xs truncate"><a class="text-muted hover:text-ink hover:underline" href="{{ $reportUrl([...$summary['period']->query(), ...array_filter($filters), 'path' => $row['path']]) }}">{{ $row['path'] }}</a></td>
                        <td class="text-right tabular-nums">{{ number_format($row['pageviews']) }}</td>
                        <td class="text-right tabular-nums">{{ $row['seconds'] === null ? '—' : ($row['seconds'] >= 60 ? intdiv($row['seconds'], 60).'m '.str_pad((string) ($row['seconds'] % 60), 2, '0', STR_PAD_LEFT).'s' : $row['seconds'].'s') }}</td>
                        <td class="text-right tabular-nums">{{ $row['scroll'] === null ? '—' : $row['scroll'].'%' }}</td>
                    </tr>
                @endforeach
            </x-signal.ui.table>
        </x-signal.ui.card>
    @endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($lists as [$title, $items, $empty, $filterKey])
            <x-signal.ui.card as="section" class="p-5" :aria-label="$title">
                <h2 class="font-extrabold text-ink">{{ $title }}</h2>
                <ul class="mt-4 grid gap-2.5 text-sm">
                    @forelse ($items as $item)
                        <li class="flex items-center justify-between gap-4">
                            @php($label = $filterKey === 'country' ? \App\Support\Country::label($item['label']) : $item['label'])
                            @if ($filterKey !== null && $item['label'] !== 'Unknown')
                                <a class="truncate text-muted hover:text-ink hover:underline" href="{{ $reportUrl([...$summary['period']->query(), ...array_filter($filters), $filterKey => $item['label']]) }}">{{ $label }}</a>
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
                    <li class="flex items-center justify-between gap-4"><span class="truncate text-muted">{{ $goal['name'] }}</span><span class="shrink-0 text-right tabular-nums">@if ($goal['revenue'] ?? null)<span class="mr-2 text-xs font-bold text-success">{{ $goal['revenue'] }}</span>@endif<strong class="text-ink">{{ number_format($goal['value']) }}</strong></span></li>
                @empty
                    <li class="text-muted">{{ __('Add a page or event goal to measure conversions.') }}</li>
                @endforelse
            </ul>
        </x-signal.ui.card>
        @include('analytics._live', ['recent' => $summary['recent']])
    </div>
