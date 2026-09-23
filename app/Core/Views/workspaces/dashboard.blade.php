@php
    $products = [
        'deployer' => ['label' => __('Deployer'), 'description' => __('Builds and deployments')],
        'monitor' => ['label' => __('Monitor'), 'description' => __('Checks and incidents')],
        'analytics' => ['label' => __('Analytics'), 'description' => __('Traffic and site reports')],
    ];
@endphp

<x-signal.layouts.platform
    :title="__('Workspace overview')"
    :description="__('A shared view of your projects and connected applications.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name"
        :title="__('Workspace overview')"
        :description="__('Your projects across Deployer, Monitor, and Analytics.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.projects.index', $workspace)">
                {{ __('View projects') }}
            </x-signal.ui.button>
            @if ($canCreateProjects)
                <x-signal.ui.button variant="primary" :href="route('core.projects.create', $workspace)">
                    <svg class="h-4 w-4 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#plus"></use></svg>
                    {{ __('New project') }}
                </x-signal.ui.button>
            @endif
        </x-slot:actions>
    </x-signal.ui.page-header>

    <dl aria-label="{{ __('Workspace summary') }}" class="mb-8 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-signal.ui.stat :label="__('Projects')" :value="$projectCount" :description="__('Projects you can access in this workspace')" />
        <x-signal.ui.stat :label="__('App connections')" :value="$activeProductCount" :description="__('Product modules active on your projects')" />
        <x-signal.ui.stat :label="__('Workflows')" :value="$connectionCount" :description="__('Enabled links between applications')" />
        <x-signal.ui.stat :label="__('Team members')" :value="$memberCount" :description="__('People with workspace membership')" />
    </dl>

    <section aria-labelledby="workspace-saved-views-title" class="mb-8">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="ui-eyebrow">{{ __('Workspace dashboard') }}</p>
                <h2 id="workspace-saved-views-title" class="mt-1 text-lg font-extrabold text-ink">{{ __('Saved views') }}</h2>
            </div>
            <p class="text-xs text-muted">{{ __('Views filter project activity. Workspace totals, subscriptions, and connections remain unchanged.') }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-signal.ui.link :href="route('core.workspace.dashboard', ['workspace' => $workspace, 'view' => 'all'])" :variant="$selectedView === null ? 'primary' : 'muted'" size="sm" :aria-current="$selectedView === null ? 'page' : null">
                {{ __('All projects') }}
            </x-signal.ui.link>
            @foreach ($dashboardViews as $savedView)
                <x-signal.ui.link :href="route('core.workspace.dashboard', ['workspace' => $workspace, 'view' => $savedView->getKey()])" :variant="$selectedView?->is($savedView) ? 'primary' : 'muted'" size="sm" :aria-current="$selectedView?->is($savedView) ? 'page' : null">
                    {{ $savedView->name }}
                    <x-signal.ui.badge :tone="$savedView->visibility === 'workspace' ? 'accent' : 'neutral'">{{ $savedView->visibility === 'workspace' ? __('Workspace') : __('Personal') }}</x-signal.ui.badge>
                </x-signal.ui.link>
            @endforeach

            <x-signal.ui.menu align="right" trigger-class="ui-btn ui-btn-secondary ui-btn-sm items-center" panel-class="w-[min(34rem,calc(100vw-2rem))] p-0" data-signal-menu>
                <x-slot:trigger>{{ __('Save a view') }}</x-slot:trigger>
                <x-signal.ui.card class="p-4 sm:p-5">
                    <h3 class="mb-4 text-base font-extrabold text-ink">{{ __('Create a saved view') }}</h3>
                    <x-signal.ui.workspace-view-form :workspace="$workspace" :can-share="$canManageWorkspace" :return-view="$selectedView?->getKey() ?? 'all'" />
                </x-signal.ui.card>
            </x-signal.ui.menu>

            @if ($dashboardViews->isNotEmpty())
                <x-signal.ui.menu align="right" trigger-class="ui-btn ui-btn-ghost ui-btn-sm items-center" panel-class="max-h-[min(70vh,36rem)] w-[min(38rem,calc(100vw-2rem))] overflow-y-auto p-0" data-signal-menu>
                    <x-slot:trigger>{{ __('Manage views') }}</x-slot:trigger>
                    <x-signal.ui.card class="grid gap-3 p-4 sm:p-5">
                        @foreach ($dashboardViews as $savedView)
                            @php
                                $canManageSavedView = $savedView->visibility === 'personal'
                                    ? $savedView->owner_user_id === $user->getKey()
                                    : $canManageWorkspace;
                            @endphp
                            <div class="rounded-panel border border-line p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p class="text-sm font-extrabold text-ink">{{ $savedView->name }}</p>
                                        <p class="text-xs text-muted">
                                            {{ __(':product · :pins', ['product' => str($savedView->filters['product'] ?? 'all')->headline(), 'pins' => ($savedView->filters['pinned_only'] ?? false) ? __('pinned projects only') : __('all visible projects')]) }}
                                            @if (is_string($savedView->filters['project_name'] ?? null) && filled($savedView->filters['project_name']))
                                                · {{ __('name contains “:name”', ['name' => $savedView->filters['project_name']]) }}
                                            @endif
                                        </p>
                                    </div>
                                    @if ($canManageSavedView)
                                        <div class="flex items-center gap-2">
                                            <x-signal.ui.menu align="right" trigger-class="ui-btn ui-btn-ghost ui-btn-sm items-center" panel-class="w-[min(34rem,calc(100vw-2rem))] p-0" data-signal-menu>
                                                <x-slot:trigger>{{ __('Edit') }}</x-slot:trigger>
                                                <x-signal.ui.card class="p-4 sm:p-5">
                                                    <x-signal.ui.workspace-view-form :workspace="$workspace" :view="$savedView" :can-share="$canManageWorkspace" :return-view="$selectedView?->getKey() ?? 'all'" />
                                                </x-signal.ui.card>
                                            </x-signal.ui.menu>
                                            <form method="POST" action="{{ route('core.workspace.views.destroy', [$workspace, $savedView]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="return_view" value="{{ $selectedView?->getKey() ?? 'all' }}">
                                                <x-signal.ui.button type="submit" variant="danger" class="ui-btn-sm">{{ __('Delete') }}</x-signal.ui.button>
                                            </form>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </x-signal.ui.card>
                </x-signal.ui.menu>
            @endif
        </div>

        @if (session('success'))
            <x-signal.ui.alert tone="success" class="mt-3">{{ session('success') }}</x-signal.ui.alert>
        @endif
        @if ($selectedViewUnavailable)
            <x-signal.ui.alert tone="warning" class="mt-3">
                {{ __('This saved view has an unavailable or invalid filter. Project activity was not loaded. Restore app access or update the saved filters.') }}
            </x-signal.ui.alert>
        @endif
    </section>

    <section aria-labelledby="workspace-priorities-title" class="mb-9">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="ui-eyebrow">{{ __('Workspace priorities') }}</p>
                <h2 id="workspace-priorities-title" class="mt-1 text-lg font-extrabold text-ink">{{ __('Needs attention') }}</h2>
            </div>
            <p class="text-xs text-muted">{{ __('Recent failures and unfinished setup from projects you can access.') }}</p>
        </div>

        @if ($priorities->isEmpty())
            <x-signal.ui.card class="p-4 text-sm leading-6 text-muted">
                {{ __('No attention items were reported for recent projects from the app data currently available.') }}
            </x-signal.ui.card>
        @else
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($priorities as $priority)
                    <x-signal.ui.workspace-priority :priority="$priority" />
                @endforeach
            </div>
        @endif
    </section>

    <section aria-labelledby="workspace-products-title" class="mb-9">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="ui-eyebrow">{{ __('Separate subscriptions') }}</p>
                <h2 id="workspace-products-title" class="mt-1 text-lg font-extrabold text-ink">{{ __('Your applications') }}</h2>
            </div>
            <p class="text-xs text-muted">{{ __('Each app keeps its own plan and usage limits.') }}</p>
        </div>

        <div class="grid gap-3 lg:grid-cols-3">
            @foreach ($products as $key => $product)
                @php
                    $subscription = $subscriptions->get($key)?->subscription;
                    $grant = $productGrants->get($key);
                    $subscriptionTone = match ($subscription?->status) {
                        'active', 'trialing' => 'success',
                        'past_due', 'incomplete', 'paused' => 'warning',
                        'canceled', 'unpaid' => 'danger',
                        default => 'neutral',
                    };
                @endphp
                <x-signal.ui.card class="p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-base font-extrabold text-ink">{{ $product['label'] }}</h3>
                            <p class="mt-1 text-xs text-muted">{{ $product['description'] }}</p>
                        </div>
                        @if ($canManageBilling)
                            @if ($subscription)
                                <x-signal.ui.badge :tone="$subscriptionTone">{{ str($subscription->status)->headline() }}</x-signal.ui.badge>
                            @else
                                <x-signal.ui.badge tone="neutral">{{ __('No plan') }}</x-signal.ui.badge>
                            @endif
                        @elseif ($grant)
                            <x-signal.ui.badge tone="success">{{ __('Access granted') }}</x-signal.ui.badge>
                        @else
                            <x-signal.ui.badge tone="neutral">{{ __('No access') }}</x-signal.ui.badge>
                        @endif
                    </div>

                    @if ($canManageBilling)
                        <p class="mt-5 text-lg font-extrabold text-ink">{{ $subscription?->plan_key ? str($subscription->plan_key)->headline() : __('Not subscribed') }}</p>
                        <p class="mt-1 text-xs text-muted">
                            @if ($subscription?->current_period_ends_at)
                                {{ __('Current period ends :date', ['date' => $subscription->current_period_ends_at->toFormattedDateString()]) }}
                            @else
                                {{ __('Billed separately for this workspace') }}
                            @endif
                        </p>
                    @else
                        <p class="mt-5 text-sm font-bold text-muted">{{ __('Billing details are visible to workspace owners and billing managers.') }}</p>
                    @endif
                </x-signal.ui.card>
            @endforeach
        </div>
    </section>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(19rem,0.85fr)]">
        <section aria-labelledby="recent-projects-title">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Shared project directory') }}</p>
                    <h2 id="recent-projects-title" class="mt-1 text-lg font-extrabold text-ink">{{ $selectedView?->name ?? __('Recently updated') }}</h2>
                </div>
                <x-signal.ui.link :href="route('core.projects.index', $workspace)" size="sm">
                    {{ __('All projects') }}
                </x-signal.ui.link>
            </div>
            <p class="-mt-2 mb-4 text-xs text-muted">{{ $selectedView ? __('Projects matching this saved view, with current access checked on every load.') : __('Latest deployments, service health, and traffic from connected apps.') }}</p>

            @if ($projects->isEmpty())
                <x-signal.ui.empty-state
                    :title="$selectedView ? __('No projects match this view') : __('No projects yet')"
                    :description="$selectedView ? __('Try a different product filter or pin projects to this view’s scope.') : __('Create a project once, then connect Deployer, Monitor, or Analytics when your team is ready.')"
                    icon="view-grid"
                >
                    @if ($canCreateProjects)
                        <x-slot:action>
                            <x-signal.ui.button variant="primary" :href="route('core.projects.create', $workspace)">
                                {{ __('Create your first project') }}
                            </x-signal.ui.button>
                        </x-slot:action>
                    @endif
                </x-signal.ui.empty-state>
            @else
                <div class="grid gap-3">
                    @foreach ($projects as $project)
                        @php
                            $enabledProducts = $project->products->keyBy('product');
                        @endphp
                        <x-signal.ui.card class="p-4 sm:p-5">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <h3 class="truncate text-base font-extrabold text-ink">
                                        <x-signal.ui.link :href="route('core.projects.show', [$workspace, $project])" variant="muted" size="inline">{{ $project->name }}</x-signal.ui.link>
                                    </h3>
                                    @if ($project->description)
                                        <p class="mt-1 line-clamp-1 text-sm text-muted">{{ $project->description }}</p>
                                    @else
                                        <p class="mt-1 text-xs text-muted">{{ __('Updated :date', ['date' => $project->updated_at->diffForHumans()]) }}</p>
                                    @endif
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @php
                                        $pinState = $projectPinStates->get((string) $project->getKey(), ['personal' => false, 'workspace' => false]);
                                    @endphp
                                    <x-signal.ui.workspace-project-pin
                                        :workspace="$workspace"
                                        :project="$project"
                                        :state="$pinState"
                                        :can-manage-workspace="$canManageWorkspace"
                                        :return-view="$selectedView?->getKey() ?? 'all'"
                                    />
                                    <x-signal.ui.link :href="route('core.projects.show', [$workspace, $project])" size="sm">
                                        {{ __('Open project') }}
                                        <svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#arrow-right"></use></svg>
                                    </x-signal.ui.link>
                                </div>
                            </div>

                            <div class="mt-4 flex flex-wrap items-center gap-2">
                                @foreach ($products as $key => $product)
                                    @php
                                        $activation = $enabledProducts->get($key);
                                        $activationState = match (true) {
                                            ! $productGrants->has($key) => ['tone' => 'neutral', 'label' => __('No access')],
                                            $activation?->status === 'active' => ['tone' => 'success', 'label' => __('Connected')],
                                            in_array($activation?->status, ['pending', 'provisioning', 'setting_up'], true) => ['tone' => 'warning', 'label' => __('Setting up')],
                                            in_array($activation?->status, ['failed', 'error'], true) => ['tone' => 'danger', 'label' => __('Needs attention')],
                                            default => ['tone' => 'neutral', 'label' => __('Available')],
                                        };
                                    @endphp
                                    <x-signal.ui.badge :tone="$activationState['tone']">{{ $product['label'] }} · {{ $activationState['label'] }}</x-signal.ui.badge>
                                @endforeach
                                <span class="ml-auto text-xs text-muted">{{ trans_choice(':count workflow|:count workflows', $project->active_connections_count, ['count' => $project->active_connections_count]) }}</span>
                            </div>

                            @php
                                $summaries = $projectSummaries->get((string) $project->getKey(), collect());
                            @endphp
                            @if ($summaries->isNotEmpty())
                                <div class="mt-4 grid gap-2 border-t border-line pt-4 sm:grid-cols-2 xl:grid-cols-3" aria-label="{{ __('Product activity for :project', ['project' => $project->name]) }}">
                                    @foreach ($summaries as $key => $summary)
                                        <x-signal.ui.project-product-summary
                                            :summary="$summary"
                                            :product-label="$products[$key]['label'] ?? str($key)->headline()"
                                            compact
                                        />
                                    @endforeach
                                </div>
                            @endif
                        </x-signal.ui.card>
                    @endforeach
                </div>
            @endif
        </section>

        <section aria-labelledby="workspace-connections-title">
            <div class="mb-4">
                <p class="ui-eyebrow">{{ __('App-to-app automation') }}</p>
                <h2 id="workspace-connections-title" class="mt-1 text-lg font-extrabold text-ink">{{ __('Recent connections') }}</h2>
            </div>
            <x-signal.ui.card class="p-4 sm:p-5">
                @if ($recentConnections->isEmpty())
                    <p class="text-sm leading-6 text-muted">{{ __('Connect applications from a project to share approved deployment and monitoring context.') }}</p>
                    @if ($projects->isNotEmpty())
                        <x-signal.ui.link :href="route('core.projects.show', [$workspace, $projects->first()]).'#connections'" size="sm" class="mt-3">
                            {{ __('Set up a connection') }}
                            <svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#arrow-right"></use></svg>
                        </x-signal.ui.link>
                    @endif
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($recentConnections as $connection)
                            @php
                                $tone = match ($connection->status) {
                                    'active', 'connected' => 'success',
                                    'failed', 'error' => 'danger',
                                    'pending', 'retrying' => 'warning',
                                    default => 'neutral',
                                };
                                $sourceName = $connection->sourceResource?->name ?? str($connection->sourceResource?->product)->headline();
                                $targetName = $connection->targetResource?->name ?? str($connection->targetResource?->product)->headline();
                            @endphp
                            <li class="py-3 first:pt-0 last:pb-0">
                                <x-signal.ui.link :href="route('core.projects.show', [$workspace, $connection->project]).'#connections'" variant="muted" size="inline" class="group flex w-full items-start justify-between">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-extrabold text-ink group-hover:text-primary">{{ $connection->project->name }}</span>
                                        <span class="mt-1 block truncate text-xs text-muted">{{ $sourceName }} <span aria-hidden="true">→</span> {{ $targetName }}</span>
                                        <span class="mt-1 block text-[11px] text-subtle">
                                            @if ($connection->last_succeeded_at)
                                                {{ __('Last delivered :date', ['date' => $connection->last_succeeded_at->diffForHumans()]) }}
                                            @else
                                                {{ __('Created :date', ['date' => $connection->created_at->diffForHumans()]) }}
                                            @endif
                                        </span>
                                    </span>
                                    <x-signal.ui.badge :tone="$tone">{{ str($connection->status)->headline() }}</x-signal.ui.badge>
                                </x-signal.ui.link>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-signal.ui.card>
        </section>
    </div>
</x-signal.layouts.platform>
