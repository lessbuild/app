<ol class="space-y-4">
    @foreach ($entries as $entry)
        <li class="relative pl-9">
            <span @class([
                'absolute left-0 top-0 flex h-6 w-6 items-center justify-center rounded-full border border-line bg-surface-muted text-xs font-black',
            ]) style="color: {{ match ($entry->status) { 'completed' => 'var(--ui-success)', 'active' => 'var(--ui-info)', 'failed' => 'var(--ui-danger)', 'canceled' => 'var(--ui-warning)', default => 'var(--ui-muted)' } }}" aria-hidden="true">{{ match ($entry->status) { 'completed' => '✓', 'failed' => '!', 'canceled' => '–', 'active' => '•', default => '○' } }}</span>
            <div class="ui-card ui-card--muted border-line p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold text-ink">{{ __($entry->title) }}</h3>
                    <span class="ui-eyebrow text-[0.65rem]">{{ str($entry->status)->headline() }}</span>
                </div>
                <p class="mt-1 text-sm text-muted">{{ __($entry->description) }}</p>
                @if ($entry->occurredAt)
                    <time datetime="{{ $entry->occurredAt->toIso8601String() }}" class="mt-2 block text-xs text-muted">{{ $entry->occurredAt->format('Y-m-d H:i:s T') }}</time>
                @else
                    <span class="mt-2 block text-xs text-muted">{{ __('No milestone timestamp is recorded.') }}</span>
                @endif
            </div>
        </li>
    @endforeach
</ol>
