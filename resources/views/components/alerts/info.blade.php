<div class="rounded-md bg-tertiary p-3">
    <div class="flex">
        <div class="shrink-0">
            <svg class="w-5 h-5 stroke-2 text-gray-300">
                <use xlink:href="/assets/images/icons.svg#information-circle"></use>
            </svg>
        </div>
        <div class="ml-3 flex-1 md:flex md:justify-between">
            <p class="text-sm text-gray-300">
                {{ $title }}
            </p>

            @if($link ?? null)
                <p class="mt-3 text-sm md:mt-0 md:ml-6">
                    <a data-turbo="false" href="{{ $link }}" class="whitespace-nowrap font-medium text-gray-300">
                        {{ $anchor }}
                        <span aria-hidden="true">→</span>
                    </a>
                </p>
            @endif
        </div>
    </div>
</div>
