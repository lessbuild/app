{{-- One service on the project overview: open it, enable it, or say who can. --}}
<x-signal.ui.card class="flex h-full flex-col gap-4 p-5">
    <div class="flex items-start gap-3">
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-card bg-primary-soft text-[var(--ui-primary)]" aria-hidden="true">
            <svg class="h-5 w-5 stroke-2"><use xlink:href="/assets/images/icons.svg#{{ $service->icon }}"></use></svg>
        </span>
        <div class="min-w-0">
            <h3 class="flex flex-wrap items-center gap-2 font-extrabold text-ink">
                {{ $service->name }}
                @if ($service->enabled)
                    <x-signal.ui.badge tone="success">{{ __('On') }}</x-signal.ui.badge>
                @endif
            </h3>
            <p class="mt-1 text-sm text-muted">{{ $service->tagline }}</p>
        </div>
    </div>
    <div class="mt-auto">
        @if ($service->enabled && $service->canUse)
            <x-signal.ui.button :href="$service->url" variant="secondary" size="sm">{{ __('Open :service', ['service' => $service->name]) }}</x-signal.ui.button>
        @elseif (! $service->enabled && $service->canManage)
            <form method="POST" action="{{ route('projects.services.store', [$project, $service->key]) }}">
                @csrf
                <x-signal.ui.button type="submit" variant="primary" size="sm">{{ __('Turn on :service', ['service' => $service->name]) }}</x-signal.ui.button>
            </form>
        @elseif (! $service->canUse)
            <p class="text-xs text-muted">{{ __('You don’t have access to :service in this account.', ['service' => $service->name]) }}</p>
        @else
            <p class="text-xs text-muted">{{ __('Someone who manages projects can turn it on.') }}</p>
        @endif
    </div>
</x-signal.ui.card>
