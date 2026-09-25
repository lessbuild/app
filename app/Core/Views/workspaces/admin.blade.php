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
                    <x-signal.ui.button :href="route('core.workspace.blueprints.index', $workspace)" variant="secondary">{{ __('Project blueprints') }}</x-signal.ui.button>
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
                    <p class="mt-2 text-sm leading-6 text-muted">{{ __('Manage :product settings and open its operational views. Each action checks your current app permissions.', ['product' => $label]) }}</p>
                    <ul class="mt-4 grid gap-1 border-t border-line pt-3">
                        @foreach ($tools->take(4) as $tool)
                            <li>
                                <x-signal.blocks.admin-link :href="$tool['href']" :label="$tool['label']" :description="$tool['description']" />
                            </li>
                        @endforeach
                        @if ($tools->count() > 4)
                            <li>
                                <x-signal.ui.disclosure :title="__('More :product tools', ['product' => $label])">
                                    <ul class="grid gap-1">
                                        @foreach ($tools->skip(4) as $tool)
                                            <li><x-signal.blocks.admin-link :href="$tool['href']" :label="$tool['label']" :description="$tool['description']" /></li>
                                        @endforeach
                                    </ul>
                                </x-signal.ui.disclosure>
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
    @if ((string) $workspace->owner_user_id === (string) $user->getKey())
        <x-signal.ui.panel as="section" class="mt-6 space-y-3 p-6">
            <h2 class="text-lg font-extrabold text-ink">{{ __('Delete workspace') }}</h2>
            <p class="text-sm leading-6 text-muted">{{ __('Review permanent deletion of this workspace and its connected app data. Your shared account stays active.') }}</p>
            <x-signal.ui.button :href="route('platform.deletions.workspace.create', $workspace)" variant="danger">{{ __('Review workspace deletion') }}</x-signal.ui.button>
        </x-signal.ui.panel>
    @endif
</x-signal.layouts.platform>
