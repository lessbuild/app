<div class="px-3">
    <x-ui.card class="ui-stat flex w-full items-center gap-4 p-5 text-blue-400" tone="default">
        <svg class="hidden h-12 w-12 shrink-0 fill-current lg:block" aria-hidden="true">
            <use xlink:href="/assets/images/icons.svg#{{ $icon }}"></use>
        </svg>
        <div class="min-w-0">
            <p class="ui-stat__value">
                {{ $title }}
            </p>
            <p class="ui-stat__label">
                {{ $description }}
            </p>
        </div>
    </x-ui.card>
</div>
