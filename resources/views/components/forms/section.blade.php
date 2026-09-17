<div class="grid gap-6 lg:grid-cols-[minmax(0,.85fr)_minmax(0,1.5fr)]">
    <div class="hidden lg:block">
        <div class="px-4 sm:px-0">
            <h2 class="text-lg font-bold leading-tight text-primary">
                {{ $title }}
            </h2>
            <p class="text-sm text-secondary">
                {{ $description }}
            </p>
        </div>
    </div>

    <div class="ui-card overflow-hidden">
        <div class="border-b border-primary px-4 py-4 lg:hidden">
            <h2 class="font-bold text-primary">{{ $title }}</h2>
            <p class="mt-1 text-sm text-secondary">{{ $description }}</p>
        </div>
        <div>
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="border-t border-primary">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
