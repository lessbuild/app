@props([
    'events',
    'emptyTitle' => __('No activity yet'),
    'emptyDescription' => __('Infrastructure and deployment updates will appear here.'),
])

<div class="overflow-hidden rounded-lg border border-primary bg-primary">
    @forelse ($events as $event)
        @php($url = $event->url())
        <div class="flex items-start justify-between gap-4 border-b border-primary p-4 last:border-b-0">
            <div class="min-w-0">
                @if ($url)
                    <a href="{{ $url }}" class="font-medium text-primary hover:text-ternary">
                        {{ $event->event }}
                    </a>
                @else
                    <p class="font-medium text-primary">{{ $event->event }}</p>
                @endif
                <p class="mt-1 text-xs uppercase tracking-wide text-secondary">{{ $event->category }}</p>
            </div>
            <time datetime="{{ $event->created_at->toIso8601String() }}" class="shrink-0 text-sm text-secondary">
                {{ $event->created_at->diffForHumans() }}
            </time>
        </div>
    @empty
        <div class="p-6 text-center">
            <p class="font-medium text-primary">{{ $emptyTitle }}</p>
            <p class="mt-1 text-sm text-secondary">{{ $emptyDescription }}</p>
        </div>
    @endforelse
</div>
