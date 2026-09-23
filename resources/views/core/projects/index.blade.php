@php
    $products = [
        'deployer' => __('Deployer'),
        'monitor' => __('Monitor'),
        'analytics' => __('Analytics'),
    ];
@endphp

<x-signal.layouts.platform
    :title="__('Projects')"
    :description="__('Manage projects shared across Deployer, Monitor, and Analytics.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name"
        :title="__('Projects')"
        :description="__('One project directory for every connected application.')"
    >
        <x-slot:actions>
            @if ($canCreateProjects)
                <x-signal.ui.button variant="primary" :href="route('core.projects.create', $workspace)">
                    <svg class="h-4 w-4 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#plus"></use></svg>
                    {{ __('New project') }}
                </x-signal.ui.button>
            @endif
        </x-slot:actions>
    </x-signal.ui.page-header>

    <section aria-label="{{ __('Product subscriptions') }}" class="mb-8 grid gap-3 sm:grid-cols-3">
        @foreach ($products as $key => $label)
            @php
                $subscription = $subscriptions->get($key)?->subscription;
                $plan = $subscription?->plan_key;
            @endphp
            <x-signal.ui.card class="p-4">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-extrabold text-ink">{{ $label }}</h2>
                    @if (! $canManageBilling)
                        <x-signal.ui.badge tone="neutral">{{ __('Billing access') }}</x-signal.ui.badge>
                    @elseif ($subscription)
                        <x-signal.ui.badge :tone="in_array($subscription->status, ['active', 'trialing'], true) ? 'success' : 'warning'">
                            {{ str($subscription->status)->headline() }}
                        </x-signal.ui.badge>
                    @else
                        <x-signal.ui.badge tone="neutral">{{ __('No plan') }}</x-signal.ui.badge>
                    @endif
                </div>
                @if ($canManageBilling)
                    <p class="mt-3 text-lg font-extrabold text-ink">{{ $plan ? str($plan)->headline() : __('Not subscribed') }}</p>
                    <p class="mt-1 text-xs text-muted">{{ __('Billed separately for this workspace') }}</p>
                @else
                    <p class="mt-3 text-sm font-bold text-muted">{{ __('Billing details are limited to workspace owners and billing managers.') }}</p>
                @endif
            </x-signal.ui.card>
        @endforeach
    </section>

    @if ($projects->isEmpty())
        <x-signal.ui.empty-state
            :title="__('No projects yet')"
            :description="__('Create a project once, then connect the product modules your team needs.')"
            icon="view-grid"
        >
            @if ($canCreateProjects)
                <x-slot:action>
                    <x-signal.ui.button variant="primary" :href="route('core.projects.create', $workspace)">
                        {{ __('Create your first project') }}
                    </x-signal.ui.button>
                </x-slot:action>
            @else
                <x-slot:action>
                    <p class="text-sm text-muted">{{ __('Ask a workspace owner or admin to create a project.') }}</p>
                </x-slot:action>
            @endif
        </x-signal.ui.empty-state>
    @else
        <section aria-label="{{ __('Workspace projects') }}" class="grid gap-4 xl:grid-cols-2">
            @foreach ($projects as $project)
                <x-signal.ui.card tone="interactive" class="p-5 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="ui-eyebrow">{{ __('Project') }}</p>
                            <h2 class="mt-2 truncate text-xl font-extrabold text-ink">
                                <a class="rounded-sm hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus" href="{{ route('core.projects.show', [$workspace, $project]) }}">
                                    {{ $project->name }}
                                </a>
                            </h2>
                            @if ($project->description)
                                <p class="mt-2 line-clamp-2 text-sm leading-6 text-muted">{{ $project->description }}</p>
                            @endif
                        </div>
                        <x-signal.ui.badge tone="success">{{ __('Active') }}</x-signal.ui.badge>
                    </div>

                    <div class="mt-5 grid gap-2 sm:grid-cols-3">
                        @foreach ($products as $key => $label)
                            @php
                                $hasAccess = $productGrants->has($key);
                                $isConnected = $hasAccess && $project->products->contains(fn ($product): bool => $product->product === $key && $product->status === 'active');
                            @endphp
                            <div class="flex min-w-0 items-center justify-between gap-2 rounded-control border border-line bg-surface-muted px-3 py-2">
                                <span class="truncate text-xs font-bold text-muted">{{ $label }}</span>
                                @if (! $hasAccess)
                                    <x-signal.ui.badge tone="neutral">{{ __('No access') }}</x-signal.ui.badge>
                                @elseif ($isConnected)
                                    <x-signal.ui.badge tone="success">{{ __('Connected') }}</x-signal.ui.badge>
                                @else
                                    <x-signal.ui.badge tone="neutral">{{ __('Not connected') }}</x-signal.ui.badge>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4 text-xs text-muted">
                        <span>{{ __('Updated :date', ['date' => $project->updated_at->diffForHumans()]) }}</span>
                        <a href="{{ route('core.projects.show', [$workspace, $project]) }}" class="inline-flex min-h-9 items-center gap-2 rounded-control px-3 font-extrabold text-primary hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                            {{ __('Open project') }}
                            <svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#arrow-right"></use></svg>
                        </a>
                    </div>
                </x-signal.ui.card>
            @endforeach
        </section>

        <div class="mt-6">{{ $projects->links() }}</div>
    @endif
</x-signal.layouts.platform>
