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
            <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
                @if (count($sites) > 1)
                    <x-signal.ui.select-field name="site" :label="__('Site')" :show-errors="false">
                        @foreach ($sites as $option)
                            <option value="{{ $option->id }}" @selected($site->id === $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                @else
                    <input type="hidden" name="site" value="{{ $site->id }}">
                @endif
                @include('analytics._period-fields')
                @foreach (['country', 'campaign', 'channel', 'region', 'city', 'screen', 'term', 'content'] as $key)
                    @if ($filters[$key] ?? null)<input type="hidden" name="{{ $key }}" value="{{ $filters[$key] }}">@endif
                @endforeach
                <x-signal.ui.input-field name="path" :label="__('Page')" :value="$filters['path']" placeholder="/pricing" :restore="false" :show-errors="false" />
                <x-signal.ui.input-field name="source" :label="__('Source')" :value="$filters['source']" :placeholder="__('newsletter or google.com')" :restore="false" :show-errors="false" />
                <x-signal.ui.select-field name="device" :label="__('Device')" :show-errors="false">
                    <option value="">{{ __('All devices') }}</option>
                    @foreach (['Desktop', 'Mobile', 'Tablet'] as $device)
                        <option value="{{ $device }}" @selected($filters['device'] === $device)>{{ $device }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.input-field name="browser" :label="__('Browser')" :value="$filters['browser']" placeholder="Firefox" :restore="false" :show-errors="false" />
                <x-signal.ui.input-field name="os" :label="__('Operating system')" :value="$filters['os']" placeholder="iOS" :restore="false" :show-errors="false" />
                <div class="flex gap-2 sm:col-span-2 lg:col-span-5">
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Apply') }}</x-signal.ui.button>
                    @if (array_filter($filters))
                        <x-signal.ui.button :href="route('analytics.overview', [$project, 'site' => $site->id, ...$period->query()])" variant="quiet">{{ __('Clear filters') }}</x-signal.ui.button>
                    @endif
                </div>
            </form>
            <x-signal.ui.saved-views page="analytics.overview" :parameters="['project' => $project->id]" class="mt-4 border-t border-line pt-4" />
        </x-signal.ui.card>

        @if (! $site->isVerified())
            <x-signal.ui.alert tone="warning" role="status"><strong>{{ $site->name }}</strong>&nbsp;{{ __('is waiting for verification before it collects.') }} <a class="ui-link" href="{{ route('analytics.sites.show', [$project, $site->id]) }}">{{ __('Finish setup') }}</a></x-signal.ui.alert>
        @elseif (! $site->isCollectionAvailable())
            <x-signal.ui.alert tone="warning" role="status">{{ __('Collection is paused for :site. Existing reports stay available.', ['site' => $site->name]) }}</x-signal.ui.alert>
        @endif

        @if ($canManage)
            <x-slot:actions>
                <x-signal.ui.button :href="route('analytics.overview', [$project, 'site' => $site->id, ...$period->query(), 'dialog' => 'new-note'])" variant="secondary" data-modal-trigger="new-note">{{ __('Add a note') }}</x-signal.ui.button>
            </x-slot:actions>
            <x-signal.overlays.form-modal id="new-note" :title="__('Add a note to the chart')" :description="__('Mark a launch, campaign or outage so it’s clear what moved the numbers.')" :action="route('analytics.annotations.store', [$project, $site->id])" :submit="__('Add note')" form-class="grid gap-4">
                <x-signal.ui.input-field name="date" type="date" :label="__('Day')" :value="now($site->timezone)->toDateString()" required />
                <x-signal.ui.input-field name="text" :label="__('Note')" maxlength="200" required />
            </x-signal.overlays.form-modal>
        @endif

        @include('analytics._report', [
            'reportUrl' => fn (array $params): string => route('analytics.overview', [$project, 'site' => $site->id, ...$params]),
            'goalsUrl' => route('analytics.goals', [$project, 'site' => $site->id]),
        ])

        <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-muted">
            <p>{{ __('Visitors are cookieless daily estimates; visits end after 30 minutes without activity. Raw detail is kept :days days.', ['days' => config('analytics.event_retention_days')]) }} {{ __('Last processed :time.', ['time' => $summary['lastProcessedAt']?->diffForHumans() ?? __('never')]) }}</p>
            <form method="POST" action="{{ route('analytics.exports.store', [$project, $site->id]) }}">
                @csrf
                @foreach ($period->query() as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                @foreach ($filters as $key => $value)
                    @if ($value !== null)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                @endforeach
                <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Export CSV') }}</x-signal.ui.button>
            </form>
        </div>
    @endif
</x-signal.layouts.project>
