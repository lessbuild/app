@php($project = $overview->project)

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
                    @foreach ([1 => __('Today'), 7 => __('Last 7 days'), 30 => __('Last 30 days'), 90 => __('Last 90 days'), 365 => __('Last 12 months')] as $value => $label)
                        <option value="{{ $value }}" @selected($days === $value)>{{ $label }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                @if ($filters['country'])<input type="hidden" name="country" value="{{ $filters['country'] }}">@endif
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

        @include('analytics._report', [
            'reportUrl' => fn (array $params): string => route('analytics.overview', [$project, 'site' => $site->id, ...$params]),
            'goalsUrl' => route('analytics.goals', [$project, 'site' => $site->id]),
        ])

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
