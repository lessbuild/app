@props(['shell'])

{{-- The sidebar: the current project's sections, then the account's. --}}
<nav {{ $attributes->class(['grid gap-6']) }} aria-label="{{ __('Main') }}">
    @if ($shell->project !== null)
        <div class="grid gap-1">
            <p class="truncate px-3 pb-1 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ $shell->project->name }}</p>
            @foreach ($shell->projectNav as $link)
                <a href="{{ $link->url }}" class="app-sidebar-link" @if ($link->current) aria-current="page" @endif>
                    @if ($link->icon)<svg class="h-4 w-4 shrink-0 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#{{ $link->icon }}"></use></svg>@endif
                    <span class="min-w-0 truncate">{{ $link->label }}</span>
                </a>
                @foreach ($link->children as $child)
                    <a href="{{ $child->url }}" class="app-sidebar-link ml-7 py-2 text-xs" @if ($child->current) aria-current="page" @endif>{{ $child->label }}</a>
                @endforeach
            @endforeach
        </div>
    @endif

    @if ($shell->accountNav !== [])
        <div class="grid gap-1">
            <p class="truncate px-3 pb-1 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ $shell->account?->name ?? __('Account') }}</p>
            @foreach ($shell->accountNav as $link)
                <a href="{{ $link->url }}" class="app-sidebar-link" @if ($link->current) aria-current="page" @endif>
                    @if ($link->icon)<svg class="h-4 w-4 shrink-0 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#{{ $link->icon }}"></use></svg>@endif
                    <span class="min-w-0 truncate">{{ $link->label }}</span>
                </a>
            @endforeach
        </div>
    @endif
</nav>
