<x-signal.layouts.platform
    :title="__('Workspace management')"
    :description="__('Manage shared workspace settings and open administration for connected apps.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
>
    <x-signal.ui.page-header
        eyebrow="{{ $workspace->name }}"
        :title="__('Workspace management')"
        :description="__('Shared workspace controls live here. Each connected app keeps its own detailed settings and operational records.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.dashboard', $workspace)" variant="secondary">{{ __('Overview') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.team.index', $workspace)" variant="secondary">{{ __('Team access') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.subscriptions', $workspace)" variant="primary">{{ __('Plans and billing') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <section class="mt-7 grid gap-4 md:grid-cols-2 xl:grid-cols-3" aria-label="{{ __('Shared workspace administration') }}">
        <x-signal.ui.card class="p-5">
            <p class="ui-eyebrow">{{ __('Core workspace') }}</p>
            <h2 class="mt-2 text-lg font-extrabold text-ink">{{ __('Projects and connections') }}</h2>
            <p class="mt-2 text-sm leading-6 text-muted">{{ __('Manage shared projects, connected app resources, environments, and cross-app workflows.') }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <x-signal.ui.button :href="route('core.projects.index', $workspace)" variant="secondary">{{ __('Projects') }}</x-signal.ui.button>
                <x-signal.ui.button :href="route('core.workspace.workflows', $workspace)" variant="secondary">{{ __('Workflows') }}</x-signal.ui.button>
                <x-signal.ui.button :href="route('core.workspace.deliveries', $workspace)" variant="secondary">{{ __('Delivery history') }}</x-signal.ui.button>
                <x-signal.ui.button :href="route('core.workspace.credentials', $workspace)" variant="secondary">{{ __('API credentials') }}</x-signal.ui.button>
                @if ($canManageWorkspace)
                    <x-signal.ui.button :href="route('core.workspace.feature-rollouts.index', $workspace)" variant="secondary">{{ __('Feature rollouts') }}</x-signal.ui.button>
                @endif
                <x-signal.ui.button :href="route('core.workspace.costs', $workspace)" variant="secondary">{{ __('Costs') }}</x-signal.ui.button>
                @if (($moduleTools['deployer'] ?? collect())->isNotEmpty())
                    <x-signal.ui.button :href="route('core.workspace.status-pages.index', $workspace)" variant="secondary">{{ __('Customer status pages') }}</x-signal.ui.button>
                @endif
                @if (($moduleTools['monitor'] ?? collect())->isNotEmpty())
                    <x-signal.ui.button :href="route('core.workspace.monitor-status-pages.index', $workspace)" variant="secondary">{{ __('Monitor status pages') }}</x-signal.ui.button>
                @endif
            </div>
        </x-signal.ui.card>

        <x-signal.ui.card class="p-5">
            <p class="ui-eyebrow">{{ __('Shared workspace') }}</p>
            <h2 class="mt-2 text-lg font-extrabold text-ink">{{ __('Team, access, and feedback') }}</h2>
            <p class="mt-2 text-sm leading-6 text-muted">{{ __('Manage membership and separate app access, then send feedback tied to this workspace.') }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <x-signal.ui.button :href="route('core.workspace.team.index', $workspace)" variant="secondary">{{ __('Team') }}</x-signal.ui.button>
                <x-signal.ui.button :href="route('core.workspace.feedback.index', $workspace)" variant="secondary">{{ __('Feedback') }}</x-signal.ui.button>
                <x-signal.ui.button :href="route('core.help')" variant="quiet">{{ __('Help and guides') }}</x-signal.ui.button>
            </div>
        </x-signal.ui.card>

        @foreach (['deployer' => __('Deployer'), 'monitor' => __('Monitor'), 'analytics' => __('Analytics')] as $product => $label)
            @if (($tools = $moduleTools[$product] ?? collect())->isNotEmpty())
                <x-signal.ui.card class="p-5">
                    <p class="ui-eyebrow">{{ __('App administration') }}</p>
                    <h2 class="mt-2 text-lg font-extrabold text-ink">{{ $label }}</h2>
                    <p class="mt-2 text-sm leading-6 text-muted">{{ __('These tools open the existing :product app. Its permissions are checked again before any settings or records are shown.', ['product' => $label]) }}</p>
                    <ul class="mt-4 grid gap-1 border-t border-line pt-3">
                        @foreach ($tools->take(4) as $tool)
                            <li>
                                <x-signal.blocks.admin-link :href="$tool['href']" :label="$tool['label']" :description="$tool['description']" />
                            </li>
                        @endforeach
                        @if ($tools->count() > 4)
                            <li>
                                <details class="group rounded-control border-t border-line px-3 py-2">
                                    <summary class="flex min-h-10 cursor-pointer list-none items-center justify-between gap-3 text-sm font-bold text-primary marker:hidden focus-visible:outline-2 focus-visible:outline-focus [&::-webkit-details-marker]:hidden">
                                        {{ __('More :product tools', ['product' => $label]) }}
                                        <svg class="h-4 w-4 shrink-0 rotate-90 stroke-2 transition-transform group-open:-rotate-90" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
                                    </summary>
                                    <ul class="mt-1 grid gap-1 border-t border-line pt-2">
                                        @foreach ($tools->skip(4) as $tool)
                                            <li><x-signal.blocks.admin-link :href="$tool['href']" :label="$tool['label']" :description="$tool['description']" /></li>
                                        @endforeach
                                    </ul>
                                </details>
                            </li>
                        @endif
                    </ul>
                </x-signal.ui.card>
            @endif
        @endforeach
    </section>

    @if (! $canManageWorkspace)
        <x-signal.ui.alert class="mt-6" tone="info">{{ __('Some shared workspace changes are reserved for owners and administrators. App-specific permissions still apply inside each app.') }}</x-signal.ui.alert>
    @endif
</x-signal.layouts.platform>
