@props(['title', 'open' => false])

<details @if ($open) open @endif {{ $attributes->class(['group rounded-panel border border-line bg-surface']) }}>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-panel px-4 py-3 text-sm font-bold text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
        {{ $title }}
        <svg class="h-4 w-4 shrink-0 stroke-2 transition-transform group-open:rotate-90" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
    </summary>
    <div class="border-t border-line p-4">{{ $slot }}</div>
</details>
