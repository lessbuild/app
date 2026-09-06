<section class="flex flex-col mb-6">
    <div class="flex flex-col lg:flex-row justify-between">
        <div class="flex items-center">
            @isset($title)
                <svg class="mr-3 w-12 h-12 text-primary">
                    <use xlink:href="/assets/images/icons.svg#{{ $icon ?? 'cog' }}"></use>
                </svg>
                <div class="flex flex-col justify-center">
                    <h1 class="max-w-2xl break-words text-2xl font-bold leading-tight text-primary sm:text-3xl lg:mr-5 lg:truncate">
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
