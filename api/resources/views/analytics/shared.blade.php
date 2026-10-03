{{-- A shared site report: read-only, for people without an account. Not indexed by search engines. --}}
<x-signal.layouts.base :title="__(':site analytics', ['site' => $site->name])" :description="__('Visitor analytics for :site.', ['site' => $site->name])">
    <main id="main-content" tabindex="-1" @class(['mx-auto grid max-w-6xl gap-6 px-4 sm:px-8', 'py-10' => ! $embed, 'py-4' => $embed]) @if ($branding['color'] ?? null) style="--ui-primary: {{ $branding['color'] }}" @endif>
        <header class="flex flex-wrap items-end justify-between gap-4">
            @unless ($embed)
                <div class="flex items-center gap-4">
                    @if ($branding)<x-signal.ui.brand :branding="$branding" />@endif
                    <div>
                    <p class="ui-eyebrow">{{ $reportRoute === 'analytics.viewer' ? __('View-only access') : __('Shared report') }}</p>
                    <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-ink">{{ $site->name }}</h1>
                    <p class="mt-1 text-sm text-muted">{{ implode(', ', $site->domains) }}</p>
                    </div>
                </div>
            @endunless
            <form method="GET" class="flex flex-wrap items-end gap-2">
                @foreach (array_filter($filters) as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                @include('analytics._period-fields')
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Show') }}</x-signal.ui.button>
                @if (array_filter($filters))
                    <x-signal.ui.button :href="route($reportRoute, [$token, ...$period->query()])" variant="quiet">{{ __('Clear filters') }}</x-signal.ui.button>
                @endif
            </form>
        </header>

        @include('analytics._report', [
            'reportUrl' => fn (array $params): string => route($reportRoute, [$token, ...$params]),
            'goalsUrl' => null,
        ])

        <p class="text-xs text-muted">{{ __('Visitors are cookieless daily estimates; visits end after 30 minutes without activity.') }} @unless ($branding){{ __('Measured with :app.', ['app' => config('app.name')]) }}@endunless</p>
    </main>
</x-signal.layouts.base>
