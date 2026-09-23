@props([
    'title' => null,
    'description' => null,
    'icon' => 'information-circle',
])

<div data-ui-feedback="empty" {{ $attributes->class(['ui-card', 'p-8', 'text-center']) }}>
    <div class="mx-auto max-w-2xl">
        <span class="mx-auto grid h-11 w-11 place-items-center rounded-card bg-primary-soft text-[var(--ui-primary)]">
            <svg class="h-5 w-5 stroke-2" aria-hidden="true">
                <use xlink:href="/assets/images/icons.svg#{{ $icon }}"></use>
            </svg>
        </span>

        @if ($title)
            <h2 class="mt-4 text-base font-extrabold text-ink">{{ $title }}</h2>
        @endif
        @if ($description)
            <p class="mt-2 text-sm leading-6 text-muted">{{ $description }}</p>
        @endif

        @isset($action)
            <div class="mt-6 flex flex-wrap justify-center gap-2">{{ $action }}</div>
        @endisset
    </div>
</div>
