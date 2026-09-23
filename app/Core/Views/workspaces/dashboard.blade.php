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
        <x-signal.ui.card class="p-4 sm:p-5">
            <x-signal.ui.stat :label="__('Projects')" :value="$projectCount" :description="__('Projects you can access in this workspace')" />
        </x-signal.ui.card>
        <x-signal.ui.card class="p-4 sm:p-5">
            <x-signal.ui.stat :label="__('App connections')" :value="$activeProductCount" :description="__('Product modules active on your projects')" />
        </x-signal.ui.card>
        <x-signal.ui.card class="p-4 sm:p-5">
            <x-signal.ui.stat :label="__('Workflows')" :value="$connectionCount" :description="__('Enabled links between applications')" />
        </x-signal.ui.card>
        <x-signal.ui.card class="p-4 sm:p-5">
            <x-signal.ui.stat :label="__('Team members')" :value="$memberCount" :description="__('People with workspace membership')" />
        </x-signal.ui.card>
    </dl>

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
                    <h2 id="recent-projects-title" class="mt-1 text-lg font-extrabold text-ink">{{ __('Recently updated') }}</h2>
                </div>
                <x-signal.ui.link :href="route('core.projects.index', $workspace)" size="sm">
                    {{ __('All projects') }}
                </x-signal.ui.link>
            </div>

            @if ($projects->isEmpty())
                <x-signal.ui.empty-state
                    :title="__('No projects yet')"
                    :description="__('Create a project once, then connect Deployer, Monitor, or Analytics when your team is ready.')"
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
                                <x-signal.ui.link :href="route('core.projects.show', [$workspace, $project])" size="sm">
                                    {{ __('Open project') }}
                                    <svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#arrow-right"></use></svg>
                                </x-signal.ui.link>
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
