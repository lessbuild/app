@props(['eyebrow' => null, 'eyebrowIcon' => null, 'title', 'description' => null])

<header {{ $attributes->class(['flex flex-col justify-between gap-4 sm:flex-row sm:items-end']) }}>
    <div class="flex min-w-0 items-center gap-4">
        @isset($leading)<div class="shrink-0">{{ $leading }}</div>@endisset
        <div class="min-w-0">
            @if($eyebrow)
                <p class="ui-eyebrow flex items-center gap-2 wrap-anywhere">
                    @if($eyebrowIcon)<x-monitor::icon :name="$eyebrowIcon" class="h-4 w-4 shrink-0" />@endif
                    {{ $eyebrow }}
                </p>
            @endif
            <h1 class="mt-2 wrap-anywhere text-2xl font-bold tracking-tight text-ink sm:text-3xl">{{ $title }}</h1>
            @if($description)<p class="mt-2 max-w-3xl wrap-anywhere text-sm leading-5 text-muted">{{ $description }}</p>@endif
            @isset($metadata)<div {{ $metadata->attributes->class(['mt-3 flex min-w-0 flex-wrap items-center gap-2']) }}>{{ $metadata }}</div>@endisset
        </div>
    </div>
    @isset($actions)
        <div class="flex min-w-0 shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</header>
