@props([
    'events',
    'emptyTitle' => __('No activity yet'),
    'emptyDescription' => __('Infrastructure and deployment updates will appear here.'),
])

<div data-activity-feed class="ui-panel ui-inventory-list overflow-hidden" aria-label="{{ __('Activity feed') }}">
    @forelse ($events as $event)
        @php($url = $event->url())
        <article data-activity-event class="flex items-start justify-between gap-4 border-b border-line p-4 last:border-b-0 sm:p-5">
            <div class="min-w-0">
                @if ($url)
                    <a href="{{ $url }}" class="ui-link break-words">
                        {{ $event->event }}
                    </a>
                @else
                    <p class="font-medium text-ink">{{ $event->event }}</p>
                @endif
                <p class="ui-eyebrow mt-2 text-[0.65rem]">{{ $event->category }}</p>
            </div>
            <time datetime="{{ $event->created_at->toIso8601String() }}" class="shrink-0 text-xs text-muted sm:text-sm">
                {{ $event->created_at->diffForHumans() }}
            </time>
        </article>
    @empty
        <div class="p-6 text-center">
            <p class="font-medium text-ink">{{ $emptyTitle }}</p>
            <p class="mt-1 text-sm text-muted">{{ $emptyDescription }}</p>
        </div>
    @endforelse
</div>
