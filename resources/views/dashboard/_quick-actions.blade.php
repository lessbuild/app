<section class="ui-card mb-6 p-4 sm:p-5" aria-labelledby="dashboard-quick-actions-title" data-dashboard-quick-actions>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Next actions') }}</p>
            <h2 id="dashboard-quick-actions-title" class="mt-1 text-xl font-semibold text-primary">{{ __('Quick actions') }}</h2>
            <p class="mt-1 text-sm leading-6 text-secondary">{{ __('Jump straight to the work that moves this workspace forward.') }}</p>
        </div>
        <a href="{{ route('search.index') }}" class="text-sm font-semibold text-ternary underline">{{ __('Search workspace') }}</a>
    </div>

    <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            [__('Create application'), route('projects.create'), __('Start with a repository-backed application.'), 'primary'],
            [__('Provision server'), route('servers.create'), __('Add the compute that will run your sites.'), 'secondary'],
            [__('Add website'), route('websites.create'), __('Connect a domain and deployment target.'), 'secondary'],
            [__('Open observability'), route('observability.index'), __('Review health, alerts and incidents.'), 'secondary'],
        ] as [$label, $url, $description, $variant])
            <a href="{{ $url }}" @class([
                'ui-dashboard-quick-action ui-card ui-card--interactive flex min-h-16 items-center justify-between gap-3 px-4 py-3',
                'ui-dashboard-quick-action--primary' => $variant === 'primary',
            ])>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-bold text-primary">{{ $label }}</span>
                    <span class="mt-0.5 block truncate text-xs text-secondary">{{ $description }}</span>
                </span>
                <span class="shrink-0 text-lg leading-none text-ternary" aria-hidden="true">→</span>
            </a>
        @endforeach
    </div>
</section>
