{{-- The "right now" panel: refreshes itself every 15 seconds (the page answers the refresh with just this panel). --}}
<x-signal.ui.card as="section" id="analytics-live" class="p-5" aria-labelledby="live-heading" data-live-region data-live-interval="15000">
    <div class="flex items-center justify-between gap-3">
        <h2 id="live-heading" class="flex items-center gap-2 font-extrabold text-ink">
            <span class="relative flex size-2.5" aria-hidden="true">
                @if ($recent['visitorCount'] > 0)<span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60 motion-reduce:hidden"></span>@endif
                <span @class(['relative inline-flex size-2.5 rounded-full', 'bg-success' => $recent['visitorCount'] > 0, 'bg-line' => $recent['visitorCount'] === 0])></span>
            </span>
            {{ __('Right now') }}
        </h2>
        <span class="text-sm font-bold text-ink tabular-nums">{{ trans_choice(':count visitor in the last 5 minutes|:count visitors in the last 5 minutes', $recent['visitorCount'], ['count' => $recent['visitorCount']]) }}</span>
    </div>
    <ul class="mt-3 divide-y divide-line text-sm">
        @forelse ($recent['events'] as $event)
            <li class="flex items-center justify-between gap-4 py-2.5">
                <span class="min-w-0"><span class="block truncate font-bold text-ink">{{ $event['path'] }}</span><span class="text-xs text-muted">{{ ucfirst($event['type']) }} · {{ $event['source'] }}</span></span>
                <time class="shrink-0 text-xs text-muted" datetime="{{ $event['occurredAt']->toIso8601String() }}">{{ $event['occurredAt']->diffForHumans() }}</time>
            </li>
        @empty
            <li class="py-2.5 text-muted">{{ __('No traffic in the last five minutes.') }}</li>
        @endforelse
    </ul>
</x-signal.ui.card>
