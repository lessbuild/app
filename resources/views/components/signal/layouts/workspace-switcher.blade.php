@props([
    'currentWorkspace' => null,
    'workspaceOptions' => [],
    'switchRoute' => 'organizations.switch',
    'manageUrl' => null,
    'variant' => 'topbar',
])

@php
    $isMobile = $variant === 'mobile';
    $currentWorkspaceId = data_get($currentWorkspace, 'id');
@endphp

<details
    data-signal-menu
    data-workspace-switcher
    class="group relative {{ $isMobile ? 'w-full' : 'hidden max-w-56 md:block' }}"
    @click.outside="$el.open = false"
    @keydown.escape.stop="$el.open = false; $el.querySelector('summary')?.focus()"
>
    <summary
        class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-control border border-line bg-surface px-3 text-sm font-bold text-ink marker:hidden hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus [&::-webkit-details-marker]:hidden {{ $isMobile ? 'w-full justify-between' : 'max-w-56' }}"
        aria-label="{{ __('Switch workspace') }}"
    >
        <svg class="h-4 w-4 shrink-0 stroke-2 text-primary" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#user-circle"></use></svg>
        <span class="min-w-0 flex-1 truncate text-left">{{ data_get($currentWorkspace, 'name', __('Select workspace')) }}</span>
        <x-signal.ui.badge class="shrink-0">{{ count($workspaceOptions) }}</x-signal.ui.badge>
        <svg class="h-3.5 w-3.5 shrink-0 rotate-90 stroke-2 text-muted transition-transform group-open:-rotate-90" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
    </summary>

    <div
        class="z-50 mt-2 grid max-h-[min(60vh,28rem)] min-w-64 gap-1 overflow-y-auto rounded-panel border border-line bg-surface p-2 shadow-panel {{ $isMobile ? 'relative w-full' : 'absolute right-0 top-full' }}"
        role="group"
        aria-label="{{ __('Available workspaces') }}"
    >
        <p class="px-3 py-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ __('Switch workspace') }}</p>

        @forelse ($workspaceOptions as $workspace)
            <form method="POST" action="{{ route($switchRoute, $workspace) }}">
                @csrf
                <x-signal.ui.button
                    type="submit"
                    variant="quiet"
                    @class([
                        'flex min-h-10 w-full items-center gap-2 rounded-control px-3 text-left text-sm font-bold',
                        'bg-primary-soft text-primary' => (string) $currentWorkspaceId === (string) data_get($workspace, 'id'),
                        'text-muted hover:bg-surface-muted hover:text-ink' => (string) $currentWorkspaceId !== (string) data_get($workspace, 'id'),
                    ])
                    @if ((string) $currentWorkspaceId === (string) data_get($workspace, 'id')) aria-current="true" @endif
                >
                    <span class="min-w-0 flex-1 truncate">{{ data_get($workspace, 'name') }}</span>
                    @if ((string) $currentWorkspaceId === (string) data_get($workspace, 'id'))
                        <span aria-hidden="true">✓</span>
                    @endif
                </x-signal.ui.button>
            </form>
        @empty
            <p class="px-3 py-2 text-sm text-muted">{{ __('No other workspaces are available.') }}</p>
        @endforelse

        @if ($manageUrl)
            <a href="{{ $manageUrl }}" class="mt-1 rounded-control border-t border-line px-3 py-3 text-sm font-bold text-primary hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-focus">
                {{ __('Manage workspace') }}
            </a>
        @endif
    </div>
</details>
