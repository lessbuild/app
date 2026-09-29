{{-- A shared site report: read-only, for people without an account. Not indexed by search engines. --}}
<x-signal.layouts.base :title="__(':site analytics', ['site' => $site->name])" :description="__('Visitor analytics for :site.', ['site' => $site->name])">
    <main id="main-content" tabindex="-1" class="mx-auto grid max-w-6xl gap-6 px-4 py-10 sm:px-8">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="ui-eyebrow">{{ __('Shared report') }}</p>
                <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-ink">{{ $site->name }}</h1>
                <p class="mt-1 text-sm text-muted">{{ implode(', ', $site->domains) }}</p>
            </div>
            <form method="GET" class="flex flex-wrap items-end gap-2">
                @foreach (array_filter($filters) as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                <x-signal.ui.select-field name="days" :label="__('Period')" :show-errors="false">
                    @foreach ([1 => __('Today'), 7 => __('Last 7 days'), 30 => __('Last 30 days'), 90 => __('Last 90 days'), 365 => __('Last 12 months')] as $value => $label)
                        <option value="{{ $value }}" @selected($days === $value)>{{ $label }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Show') }}</x-signal.ui.button>
                @if (array_filter($filters))
                    <x-signal.ui.button :href="route('analytics.shared', [$token, 'days' => $days])" variant="quiet">{{ __('Clear filters') }}</x-signal.ui.button>
                @endif
            </form>
        </header>

        @include('analytics._report', [
            'reportUrl' => fn (array $params): string => route('analytics.shared', [$token, ...$params]),
            'goalsUrl' => null,
        ])

        <p class="text-xs text-muted">{{ __('Visitors are cookieless daily estimates; visits end after 30 minutes without activity.') }} {{ __('Measured with :app.', ['app' => config('app.name')]) }}</p>
    </main>
</x-signal.layouts.base>
