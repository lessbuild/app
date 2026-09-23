@props(['label', 'value', 'caption' => null, 'change' => null, 'icon' => 'activity', 'tone' => 'slate'])

<div {{ $attributes->class(['ui-stat min-w-0']) }}>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="flex min-w-0 items-center gap-2 text-xs font-bold text-muted"><x-monitor::icon :name="$icon" class="h-[17px] w-[17px] shrink-0" />{{ $label }}</h2>
        @if($change !== null)<x-monitor::ui.badge :tone="$tone">{{ $change }}</x-monitor::ui.badge>@endif
    </div>
    <p class="mt-4 wrap-anywhere text-3xl font-extrabold tracking-tight text-ink">{{ $value }}</p>
    @if($caption)<p class="mt-2 text-xs leading-5 text-muted">{{ $caption }}</p>@endif
</div>
