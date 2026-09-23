@props(['icon' => 'inbox', 'title', 'description'])

<div {{ $attributes->class(['ui-card bg-surface-muted p-6 text-center shadow-none']) }}>
    <span class="mx-auto grid h-11 w-11 place-items-center rounded-control bg-surface text-subtle"><x-monitor::icon :name="$icon" class="h-[19px] w-[19px]" /></span>
    <h2 class="mt-4 text-sm font-extrabold text-ink">{{ $title }}</h2>
    <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-muted">{{ $description }}</p>
    @isset($action)<div class="mt-5">{{ $action }}</div>@endisset
</div>
