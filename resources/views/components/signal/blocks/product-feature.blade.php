@props(['title', 'description'])

<li {{ $attributes->class(['flex gap-3 rounded-control border border-line bg-surface p-4']) }}>
    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-emphasis text-[0.65rem] font-extrabold text-emphasis-ink" aria-hidden="true">✓</span>
    <div>
        <h4 class="text-sm font-bold text-ink">{{ $title }}</h4>
        <p class="mt-1 text-sm leading-6 text-muted">{{ $description }}</p>
    </div>
</li>
