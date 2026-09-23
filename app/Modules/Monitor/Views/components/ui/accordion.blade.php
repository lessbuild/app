@props(['title', 'open' => false])

<details @if($open) open @endif {{ $attributes->class(['ui-card bg-surface-muted p-4 shadow-none']) }}>
    <summary class="cursor-pointer text-xs font-extrabold text-ink">{{ $title }}</summary>
    <div class="mt-4 space-y-4">{{ $slot }}</div>
</details>
