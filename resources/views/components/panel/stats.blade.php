<div class="px-3">
    <div class="w-full bg-primary border-primary border text-blue-400 rounded-lg flex items-center p-6 mb-6 xl:mb-0">
        <svg class="w-16 h-16 fill-current mr-4 hidden lg:block">
            <use xlink:href="/assets/images/icons.svg#{{ $icon }}"></use>
        </svg>
        <div>
            <p class="font-semibold text-3xl text-primary">
                {{ $title }}
            </p>
            <p class="text-secondary">
                {{ $description }}
            </p>
        </div>
    </div>
</div>
