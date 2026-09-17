<div class="grid grid-cols-5 gap-6">
    <div class="col-span-2 hidden lg:block">
        <div class="px-4 sm:px-0">
            <h3 class="text-lg font-bold leading-tight text-primary">
                {{ $title }}
            </h3>
            <p class="text-sm text-secondary">
                {{ $description }}
            </p>
        </div>
    </div>

    <div class="ui-card mt-5 col-span-5 overflow-hidden lg:col-span-3">
        <div class="shadow-sm sm:overflow-hidden">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="border-t border-primary">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
