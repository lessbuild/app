<div class="ui-alert ui-alert--info">
    <div class="flex">
        <div class="shrink-0">
            <svg class="h-5 w-5 stroke-2" aria-hidden="true">
                <use xlink:href="/assets/images/icons.svg#information-circle"></use>
            </svg>
        </div>
        <div class="ml-3 flex-1 md:flex md:justify-between">
            <p>
                {{ $title }}
            </p>

            @if($link ?? null)
                <p class="mt-3 text-sm md:mt-0 md:ml-6">
                    <a data-turbo="false" href="{{ $link }}" class="whitespace-nowrap font-semibold underline">
                        {{ $anchor }}
                        <span aria-hidden="true">→</span>
                    </a>
                </p>
            @endif
        </div>
    </div>
</div>
