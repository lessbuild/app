<div class="grid grid-cols-5 gap-6">
    <div class="col-span-2 hidden lg:block">
        <div class="px-4 sm:px-0">
            <h3 class="text-xl font-bold pb-2 leading-loose underline text-primary">
                {{ $title }}
            </h3>
            <p class="text-sm text-secondary">
                {{ $description }}
            </p>
        </div>
    </div>

    <div class="mt-5 mt-0 col-span-5 lg:col-span-3 border border-primary rounded-sm overflow-hidden">
        <div class="shadow-sm rounded-t sm:overflow-hidden">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="border-t border-primary">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
