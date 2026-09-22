@props(['items' => []])

<div {{ $attributes->class(['space-y-2']) }}>
    @foreach ($items as $item)
        <details class="group rounded-card border border-line bg-surface p-4">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-sm font-extrabold text-ink">
                <span>{{ $item['q'] }}</span>
                <svg class="shrink-0 text-primary transition group-open:rotate-45" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 5v14M5 12h14" />
                </svg>
            </summary>
            <p class="mt-3 max-w-xl text-sm leading-6 text-muted">{{ $item['a'] }}</p>
        </details>
    @endforeach
</div>
