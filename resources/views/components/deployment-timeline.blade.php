<ol class="space-y-4">
    @foreach ($entries as $entry)
        <li class="relative pl-9">
            <span @class([
                'absolute left-0 top-0 flex h-6 w-6 items-center justify-center rounded-full text-xs font-black',
                'bg-green-100 text-green-700' => $entry->status === 'completed',
                'bg-blue-100 text-blue-700' => $entry->status === 'active',
                'bg-red-100 text-red-700' => $entry->status === 'failed',
                'bg-amber-100 text-amber-800' => $entry->status === 'canceled',
                'bg-secondary text-secondary' => $entry->status === 'pending',
            ]) aria-hidden="true">{{ match ($entry->status) { 'completed' => '✓', 'failed' => '!', 'canceled' => '–', 'active' => '•', default => '○' } }}</span>
            <div class="ui-card ui-card--muted p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold text-primary">{{ __($entry->title) }}</h3>
                    <span class="text-xs font-bold uppercase text-secondary">{{ str($entry->status)->headline() }}</span>
                </div>
                <p class="mt-1 text-sm text-secondary">{{ __($entry->description) }}</p>
                @if ($entry->occurredAt)
                    <time datetime="{{ $entry->occurredAt->toIso8601String() }}" class="mt-2 block text-xs text-secondary">{{ $entry->occurredAt->format('Y-m-d H:i:s T') }}</time>
                @else
                    <span class="mt-2 block text-xs text-secondary">{{ __('No milestone timestamp is recorded.') }}</span>
                @endif
            </div>
        </li>
    @endforeach
</ol>
