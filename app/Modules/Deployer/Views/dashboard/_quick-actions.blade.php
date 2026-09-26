<x-signal.ui.panel as="section" class="ui-panel mb-6 p-4 sm:p-5" aria-labelledby="dashboard-quick-actions-title" data-dashboard-quick-actions>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="ui-eyebrow">{{ __('Next actions') }}</p>
            <h2 id="dashboard-quick-actions-title" class="mt-1 text-xl font-extrabold tracking-tight text-ink">{{ __('Quick actions') }}</h2>
            <p class="mt-1 text-sm leading-6 text-muted">{{ __('Jump straight to the work that moves this workspace forward.') }}</p>
        </div>
        <a
            href="{{ route('search.index') }}"
            data-workspace-search-trigger
            data-signal-command-open
            aria-controls="signal-command-palette"
            aria-haspopup="dialog"
            class="ui-link text-sm"
        >{{ __('Search workspace') }}</a>
    </div>

    <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            [__('Create application'), $dashboardApplicationCreateUrl, __('Start with a repository-backed application.'), 'primary', 'application-create-dialog', 'application'],
            [__('Provision server'), $dashboardServerCreateUrl, __('Add the compute that will run your sites.'), 'secondary', 'server-create-dialog', 'server'],
            [__('Add website'), $dashboardWebsiteCreateUrl, __('Connect a domain and deployment target.'), 'secondary', 'website-create-dialog', 'website'],
            [__('Open observability'), route('observability.index'), __('Review health, alerts and incidents.'), 'secondary', null, null],
        ] as [$label, $url, $description, $variant, $modalId, $modalKey])
            <a
                href="{{ $url }}"
                @if ($modalId)
                    data-modal-trigger="{{ $modalId }}"
                    aria-controls="{{ $modalId }}"
                    aria-expanded="{{ ($dashboardModalOpen[$modalKey] ?? false) ? 'true' : 'false' }}"
                @endif
                @class([
                'ui-dashboard-quick-action ui-card ui-card--interactive flex min-h-16 items-center justify-between gap-3 px-4 py-3',
                'ui-dashboard-quick-action--primary' => $variant === 'primary',
            ])>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-bold text-ink">{{ $label }}</span>
                    <span class="mt-0.5 block truncate text-xs text-muted">{{ $description }}</span>
                </span>
                <span class="shrink-0 text-lg leading-none text-subtle" aria-hidden="true">→</span>
            </a>
        @endforeach
    </div>
</x-signal.ui.panel>
