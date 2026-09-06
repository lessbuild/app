<div class="border-2 border-dashed bg-primary border-primary rounded-sm flex flex-col items-center justify-center p-6 space-y-3">
    <svg class="h-24 h-24 text-primary stroke-2">
        <use xlink:href="/assets/images/icons.svg#information-circle"></use>
    </svg>
    <span class="font-bold text-xl text-primary">
        {{ $title ?? null }}
    </span>
    <p class="text-sm tracking-wider text-secondary">
        {{ $description ?? null }}
    </p>
    {{ $button ?? null }}
</div>
