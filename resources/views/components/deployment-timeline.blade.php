@php($statusTones = ['completed' => 'success', 'active' => 'accent', 'failed' => 'danger', 'canceled' => 'warning', 'pending' => 'neutral'])

<ol class="ui-timeline space-y-4">
    @foreach ($entries as $entry)
        <li class="ui-timeline-item">
            <div class="ui-card ui-card--muted border-line p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold text-ink">{{ __($entry->title) }}</h3>
                    <x-ui.badge :tone="$statusTones[$entry->status] ?? 'neutral'">{{ str($entry->status)->headline() }}</x-ui.badge>
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
