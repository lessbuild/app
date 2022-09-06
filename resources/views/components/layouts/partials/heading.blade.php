<section class="flex flex-col mb-6">
    <div class="flex flex-col lg:flex-row justify-between">
        <div class="flex items-center">
            @isset($title)
                <svg class="mr-3 w-12 h-12 text-primary">
                    <use xlink:href="/assets/images/icons.svg#{{ $icon ?? 'cog' }}"></use>
                </svg>
                <div class="flex flex-col justify-center">
                    <h1 class="text-3xl text-primary font-bold inline-block mr-5 max-w-2xl truncate">
                        {{ $title }}
                    </h1>
                    <p class="text-secondary">
                        {{ $description ?? null }}
                    </p>
                </div>
            @endisset
        </div>
        <div class="flex items-center justify-end lg:justify-start gap-2 mt-4 lg:mt-0">
            {{ $buttons ?? null }}
        </div>
    </div>
</section>
