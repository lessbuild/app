<ul class="mt-3 divide-y divide-line">
    @foreach ($projects as $item)
        <li class="flex flex-wrap items-center justify-between gap-2 py-3">
            <div class="min-w-0">
                <a href="{{ route('projects.show', $item) }}" class="font-bold text-ink hover:underline">{{ $item->name }}</a>
                <p class="text-xs text-muted">{{ trans_choice(':count environment|:count environments', $item->environments_count ?? 0, ['count' => $item->environments_count ?? 0]) }}</p>
            </div>
            <span class="text-xs text-muted">{{ $item->last_received_at ? __('Telemetry :time', ['time' => \Carbon\CarbonImmutable::parse($item->last_received_at)->diffForHumans()]) : __('No telemetry yet') }}</span>
        </li>
    @endforeach
</ul>
