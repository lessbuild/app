<div class="text-sm font-semibold mb-10 flex justify-between">
    <div>
        <a href="{{ $route }}" class="flex items-center text-secondary -ml-3">
            <svg class="w-3 h-3 mx-3 text-secondary stroke-2">
                <use xlink:href="/assets/images/icons.svg#chevron-left"></use>
            </svg>
            {{ $title }}
        </a>
    </div>
    {{ $buttons ?? null }}
</div>
