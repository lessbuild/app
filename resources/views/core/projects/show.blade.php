@php
    $products = [
        'deployer' => __('Deployer'),
        'monitor' => __('Monitor'),
        'analytics' => __('Analytics'),
    ];
    $navigation = [
        'groups' => [[
            'label' => __('Project'),
            'items' => [
                ['label' => __('Overview'), 'href' => route('core.projects.show', [$workspace, $project]), 'active' => ['core.projects.show']],
                ['label' => __('Resources'), 'href' => '#resources'],
                ['label' => __('Connections'), 'href' => '#connections'],
                ['label' => __('Team access'), 'href' => '#team-access'],
            ],
        ]],
    ];
    $environmentOptions = $project->environments->map(fn ($environment): array => [
        'id' => $environment->getKey(),
        'name' => $environment->name,
        'href' => route('core.projects.show', [$workspace, $project]).'#environment-'.$environment->getKey(),
    ]);
@endphp

<x-signal.layouts.platform
    :title="$project->name"
    :description="$project->description ?? __('Deployment, monitoring, and analytics for :project.', ['project' => $project->name])"
    :navigation="$navigation"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
    :current-project="$project"
    :environment-options="$environmentOptions"
    :show-environment-context="true"
    :environment-index-url="route('core.projects.show', [$workspace, $project])"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name.' · '.__('Project overview')"
        :title="$project->name"
        :description="$project->description ?? __('One shared project for your connected applications.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.projects.index', $workspace)">
                <svg class="h-4 w-4 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#view-grid"></use></svg>
                {{ __('All projects') }}
            </x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <section aria-label="{{ __('Connected products') }}" class="mb-8 grid gap-4 lg:grid-cols-3">
        @foreach ($products as $key => $label)
            @php
                $subscription = $subscriptions->get($key)?->subscription;
                $product = $project->products->firstWhere('product', $key);
                $hasAccess = $productGrants->has($key);
                $isConnected = $product !== null && $product->status === 'active';
                $productUrl = $productLinks[$key] ?? null;
            @endphp
            <x-signal.ui.card class="p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-base font-extrabold text-ink">{{ $label }}</h2>
                    @if (! $hasAccess)
                        <x-signal.ui.badge tone="neutral">{{ __('No access') }}</x-signal.ui.badge>
                    @elseif ($isConnected)
                        <x-signal.ui.badge tone="success">{{ __('Connected') }}</x-signal.ui.badge>
                    @else
                        <x-signal.ui.badge tone="neutral">{{ __('Not connected') }}</x-signal.ui.badge>
                    @endif
                </div>
                @if ($canManageBilling)
                    <p class="mt-4 text-sm text-muted">{{ __('Workspace plan') }}</p>
                    <p class="mt-1 text-lg font-extrabold text-ink">{{ $subscription?->plan_key ? str($subscription->plan_key)->headline() : __('No plan') }}</p>
                    <p class="mt-1 text-xs text-muted">{{ $subscription ? str($subscription->status)->headline() : __('Subscription is managed separately') }}</p>
                @else
                    <p class="mt-4 text-sm leading-6 text-muted">{{ __('Billing details are limited to workspace owners and billing managers.') }}</p>
                @endif
                @if ($isConnected && $productUrl)
                    <a href="{{ $productUrl }}" class="mt-5 inline-flex min-h-9 items-center gap-2 rounded-control px-3 text-sm font-extrabold text-primary hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                        {{ __('Open :product', ['product' => $label]) }}
                        <svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#arrow-up-right"></use></svg>
                    </a>
                @elseif ($hasAccess && ! $isConnected)
                    <p class="mt-5 text-xs leading-5 text-muted">{{ __('Connect this app from its product setup when you are ready.') }}</p>
                @endif
            </x-signal.ui.card>
        @endforeach
    </section>

    @if ($productSummaries->isNotEmpty())
        <section aria-label="{{ __('Recent product activity') }}" class="mb-8 grid gap-4 lg:grid-cols-3">
            @foreach ($productSummaries as $key => $summary)
                @php
                    [$summaryTone, $summaryLabel] = match ($summary->state) {
                        \App\Core\Data\Projects\ProjectProductSnapshotState::Current => ['success', __('Current')],
                        \App\Core\Data\Projects\ProjectProductSnapshotState::Attention => ['warning', __('Needs attention')],
                        \App\Core\Data\Projects\ProjectProductSnapshotState::Empty => ['neutral', __('No data yet')],
                        \App\Core\Data\Projects\ProjectProductSnapshotState::Unavailable => ['neutral', __('Unavailable')],
                    };
                @endphp
                <x-signal.ui.card class="p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="ui-eyebrow">{{ $products[$key] ?? str($key)->headline() }}</p>
                            <h2 class="mt-2 text-base font-extrabold text-ink">{{ $summary->title }}</h2>
                        </div>
                        <x-signal.ui.badge :tone="$summaryTone">{{ $summaryLabel }}</x-signal.ui.badge>
                    </div>
                    <p class="mt-4 text-sm leading-6 text-muted">{{ $summary->detail }}</p>
                    @if ($summary->updatedAt)
                        <p class="mt-3 text-xs text-subtle">
                            {{ __('Updated :time', ['time' => $summary->updatedAt->diffForHumans()]) }}
                            <time class="sr-only" datetime="{{ $summary->updatedAt->toIso8601String() }}">{{ $summary->updatedAt->toIso8601String() }}</time>
                        </p>
                    @else
                        <p class="mt-3 text-xs text-subtle">{{ __('No recent activity to report.') }}</p>
                    @endif
                    @if ($summary->url)
                        <x-signal.ui.link :href="$summary->url" size="sm" class="mt-4">
                            {{ __('Open :product', ['product' => $products[$key] ?? str($key)->headline()]) }}
                            <svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#arrow-up-right"></use></svg>
                        </x-signal.ui.link>
                    @endif
                </x-signal.ui.card>
            @endforeach
        </section>
    @endif

    @if ($project->environments->isNotEmpty())
        <section id="environments" aria-label="{{ __('Project environments') }}" class="mb-8 grid gap-4 lg:grid-cols-2">
            @foreach ($project->environments as $environment)
                <x-signal.ui.card class="p-5" id="environment-{{ $environment->getKey() }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="ui-eyebrow">{{ str($environment->environment_type)->headline() }}</p>
                            <h2 class="mt-1 text-base font-extrabold text-ink">{{ $environment->name }}</h2>
                            @if (data_get($environment->metadata, 'branch'))
                                <p class="mt-1 font-mono text-xs text-muted">{{ data_get($environment->metadata, 'branch') }}</p>
                            @endif
                        </div>
                        <x-signal.ui.badge :tone="in_array($environment->status, ['ready', 'active', 'running'], true) ? 'success' : (in_array($environment->status, ['failed', 'error'], true) ? 'danger' : 'warning')">
                            {{ str($environment->status)->headline() }}
                        </x-signal.ui.badge>
                    </div>
                    @if ($environment->resources->isNotEmpty())
                        <ul class="mt-4 grid gap-2 border-t border-line pt-4">
                            @foreach ($environment->resources as $resource)
                                <li class="flex items-center justify-between gap-3 text-sm">
                                    <span class="truncate font-bold text-ink">{{ $resource->name ?: str($resource->resource_type)->headline() }}</span>
                                    <span class="shrink-0 text-xs text-muted">{{ $products[$resource->product] ?? str($resource->product)->headline() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-signal.ui.card>
            @endforeach
        </section>
    @endif

    <section id="resources" aria-labelledby="project-resources-heading" class="mb-8">
        <x-signal.ui.card>
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                <div>
                    <p class="ui-eyebrow">{{ __('Shared project context') }}</p>
                    <h2 id="project-resources-heading" class="mt-1 text-base font-extrabold text-ink">{{ __('Project resources') }}</h2>
                </div>
                <span class="text-xs text-muted">{{ trans_choice(':count resource|:count resources', $project->resources->count(), ['count' => $project->resources->count()]) }}</span>
            </div>

            @if ($project->resources->isEmpty())
                <div class="p-5 sm:p-6">
                    <x-signal.ui.empty-state
                        :title="$project->products->isEmpty() ? __('No resources available to your account') : __('No connected resources yet')"
                        :description="$project->products->isEmpty() ? __('Ask a workspace administrator for product access, then connect the apps you need.') : __('Connect a Deployer application, Monitor service, or Analytics site to see its resources here.')"
                        icon="link"
                    />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="ui-table min-w-full">
                        <thead><tr><th scope="col">{{ __('Resource') }}</th><th scope="col">{{ __('Product') }}</th><th scope="col">{{ __('Environment') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
                        <tbody>
                            @foreach ($project->resources as $resource)
                                <tr>
                                    <td><span class="font-bold text-ink">{{ $resource->name ?: str($resource->resource_type)->headline() }}</span></td>
                                    <td>{{ $products[$resource->product] ?? str($resource->product)->headline() }}</td>
                                    <td>{{ $resource->environment?->name ?? __('All environments') }}</td>
                                    <td><x-signal.ui.badge :tone="$resource->status === 'active' ? 'success' : 'neutral'">{{ str($resource->status)->headline() }}</x-signal.ui.badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-signal.ui.card>
    </section>

    <div class="grid gap-4 xl:grid-cols-2">
        <section id="connections" aria-labelledby="project-connections-heading">
            <x-signal.ui.card class="h-full">
                <div class="border-b border-line px-5 py-4 sm:px-6">
                    <p class="ui-eyebrow">{{ __('Connected workflows') }}</p>
                    <h2 id="project-connections-heading" class="mt-1 text-base font-extrabold text-ink">{{ __('App connections') }}</h2>
                </div>
                <div class="p-5 sm:p-6">
                    @if ($canManageConnections && $connectionResources->count() >= 2)
                        <form
                            method="POST"
                            action="{{ route('core.projects.connections.store', [$workspace, $project]) }}"
                            data-project-connection-form
                            class="mb-6 grid gap-4 rounded-control border border-line bg-surface-muted p-4"
                        >
                            @csrf
                            <div class="grid gap-4 md:grid-cols-2">
                                <x-signal.ui.field
                                    name="source_resource_id"
                                    :label="__('From application')"
                                    :description="__('Choose the resource that will provide context.')"
                                    required
                                >
                                    <x-signal.ui.select name="source_resource_id" required>
                                        <option value="">{{ __('Choose a resource') }}</option>
                                        @foreach ($connectionResources as $resource)
                                            <option
                                                value="{{ $resource->getKey() }}"
                                                data-product="{{ $resource->product }}"
                                                data-resource-type="{{ $resource->resource_type }}"
                                                @selected(old('source_resource_id') === $resource->getKey())
                                            >{{ $products[$resource->product] ?? str($resource->product)->headline() }} · {{ $resource->name ?: str($resource->resource_type)->headline() }}{{ $resource->environment?->name ? ' · '.$resource->environment->name : '' }}</option>
                                        @endforeach
                                    </x-signal.ui.select>
                                </x-signal.ui.field>

                                <x-signal.ui.field
                                    name="target_resource_id"
                                    :label="__('To application')"
                                    :description="__('Choose the resource that will receive context.')"
                                    required
                                >
                                    <x-signal.ui.select name="target_resource_id" required>
                                        <option value="">{{ __('Choose a resource') }}</option>
                                        @foreach ($connectionResources as $resource)
                                            <option
                                                value="{{ $resource->getKey() }}"
                                                data-product="{{ $resource->product }}"
                                                data-resource-type="{{ $resource->resource_type }}"
                                                @selected(old('target_resource_id') === $resource->getKey())
                                            >{{ $products[$resource->product] ?? str($resource->product)->headline() }} · {{ $resource->name ?: str($resource->resource_type)->headline() }}{{ $resource->environment?->name ? ' · '.$resource->environment->name : '' }}</option>
                                        @endforeach
                                    </x-signal.ui.select>
                                </x-signal.ui.field>
                            </div>

                            <fieldset class="grid gap-2">
                                <legend class="text-sm font-bold text-ink">{{ __('Enable behavior') }}</legend>
                                <p class="text-xs leading-5 text-muted">{{ __('Only behaviors supported by the selected application direction can be enabled.') }}</p>
                                @foreach ($connectionCapabilities as $capability)
                                    <div
                                        data-connection-capability
                                        data-source-product="{{ $capability->sourceProduct() }}"
                                        data-target-product="{{ $capability->targetProduct() }}"
                                        data-source-resource-type="{{ $capability->sourceResourceType() }}"
                                        data-target-resource-type="{{ $capability->targetResourceType() }}"
                                    >
                                        <x-signal.ui.checkbox
                                            :id="'project-connection-capability-'.$capability->value"
                                            name="capabilities[]"
                                            :value="$capability->value"
                                            :checked="in_array($capability->value, (array) old('capabilities', []), true)"
                                        >
                                            {{ $capability->label() }}
                                            <span class="ml-1 text-xs font-medium text-muted">{{ $products[$capability->sourceProduct()] }} → {{ $products[$capability->targetProduct()] }}</span>
                                        </x-signal.ui.checkbox>
                                    </div>
                                @endforeach
                                <p
                                    data-connection-hint
                                    data-hint-default="{{ __('Choose two resources from a supported application direction to see available behaviors.') }}"
                                    data-hint-unavailable="{{ __('No workflow behavior is available for this application direction yet.') }}"
                                    class="text-xs leading-5 text-muted"
                                >{{ __('Choose two resources from a supported application direction to see available behaviors.') }}</p>
                                <x-forms.errors name="capabilities" />
                            </fieldset>

                            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
                            <p class="max-w-xl text-xs leading-5 text-muted">{{ __('Deployment context is delivered to Monitor when a deployment succeeds. Product data and subscriptions remain separate.') }}</p>
                                <x-signal.ui.button variant="primary" type="submit">
                                    {{ __('Save connection') }}
                                </x-signal.ui.button>
                            </div>
                        </form>
                    @elseif ($canManageConnections)
                        <x-signal.ui.empty-state
                            :title="__('No cross-app resources yet')"
                            :description="__('Map active resources from at least two applications to create a project connection.')"
                            icon="link"
                        />
                    @else
                        <p class="mb-5 text-sm leading-6 text-muted">{{ __('Ask a workspace owner or admin to manage app connections.') }}</p>
                    @endif

                    @if ($project->connections->isEmpty())
                        <p class="text-sm leading-6 text-muted">{{ __('No application connections have been configured for this project.') }}</p>
                    @else
                        <ul class="divide-y divide-line">
                            @foreach ($project->connections as $connection)
                                <li class="flex flex-wrap items-start justify-between gap-3 py-4 first:pt-0 last:pb-0">
                                    <div class="min-w-0 space-y-1">
                                        <p class="truncate text-sm font-bold text-ink">
                                            {{ $products[$connection->sourceResource?->product] ?? __('Application') }} · {{ $connection->sourceResource?->name ?: str($connection->sourceResource?->resource_type ?? 'resource')->headline() }}
                                            <span aria-hidden="true">→</span>
                                            {{ $products[$connection->targetResource?->product] ?? __('Application') }} · {{ $connection->targetResource?->name ?: str($connection->targetResource?->resource_type ?? 'resource')->headline() }}
                                        </p>
                                        <p class="text-xs text-muted">
                                            @foreach ((array) $connection->capabilities as $capabilityKey)
                                                {{ \App\Core\Enums\ProjectConnectionCapability::tryFrom($capabilityKey)?->label() ?? str($capabilityKey)->headline() }}@if (! $loop->last) · @endif
                                            @endforeach
                                            @if ($connection->last_succeeded_at)
                                                · {{ __('Last synced :date', ['date' => $connection->last_succeeded_at->diffForHumans()]) }}
                                            @elseif ($connection->status === 'pending')
                                                · {{ __('Waiting for the next deployment event') }}
                                            @endif
                                        </p>
                                        @if ($connection->last_error_code)
                                            <p class="text-xs font-semibold text-danger">{{ __('Last error: :code', ['code' => $connection->last_error_code]) }}</p>
                                        @endif
                                        @if ($connection->events->isNotEmpty())
                                            <ul class="mt-2 space-y-1 border-l-2 border-line pl-3">
                                                @foreach ($connection->events as $event)
                                                    <li class="text-xs text-muted">
                                                        {{ \App\Core\Enums\ProjectConnectionEventType::tryFrom($event->event_type)?->label() ?? str($event->event_type)->headline() }}
                                                        · {{ $event->actor?->name ?? __('Workspace administrator') }}
                                                        · {{ $event->occurred_at->diffForHumans() }}
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                        @if ($connection->deliveries->isNotEmpty())
                                            <ul class="mt-2 space-y-1 border-l-2 border-line pl-3" aria-label="{{ __('Recent delivery attempts') }}">
                                                @foreach ($connection->deliveries->take(3) as $delivery)
                                                    <li class="text-xs text-muted">
                                                        {{ str($delivery->status)->headline() }}
                                                        · {{ trans_choice(':count attempt|:count attempts', $delivery->attempts, ['count' => $delivery->attempts]) }}
                                                        @if ($delivery->last_error_code) · {{ __('Error: :code', ['code' => $delivery->last_error_code]) }} @endif
                                                        @if ($delivery->delivered_at) · {{ __('Delivered :date', ['date' => $delivery->delivered_at->diffForHumans()]) }} @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <x-signal.ui.badge :tone="in_array($connection->status, ['active', 'connected'], true) ? 'success' : (in_array($connection->status, ['failed', 'error'], true) ? 'danger' : 'warning')">{{ $connection->status === 'pending' ? __('Setup pending') : str($connection->status)->headline() }}</x-signal.ui.badge>
                                        @if ($canManageConnections)
                                            @if ($connection->deliveries->contains(fn ($delivery) => in_array($delivery->status, ['failed', 'blocked'], true)))
                                                <form method="POST" action="{{ route('core.projects.connections.retry', [$workspace, $project, $connection]) }}">
                                                    @csrf
                                                    <x-signal.ui.button type="submit" class="min-h-8 px-3 text-xs">{{ __('Retry failed deliveries') }}</x-signal.ui.button>
                                                </form>
                                            @endif
                                            <form
                                                method="POST"
                                                action="{{ route('core.projects.connections.destroy', [$workspace, $project, $connection]) }}"
                                                data-confirm="{{ __('Disconnect these applications? Existing product data will remain.') }}"
                                            >
                                                @csrf
                                                @method('DELETE')
                                                <x-signal.ui.button variant="danger" type="submit" class="min-h-8 px-3 text-xs">
                                                    {{ __('Disconnect') }}
                                                </x-signal.ui.button>
                                            </form>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </x-signal.ui.card>
        </section>

        <section id="team-access" aria-labelledby="project-team-heading">
            <x-signal.ui.card class="h-full">
                <div class="border-b border-line px-5 py-4 sm:px-6">
                    <p class="ui-eyebrow">{{ __('Access') }}</p>
                    <h2 id="project-team-heading" class="mt-1 text-base font-extrabold text-ink">{{ __('Team access') }}</h2>
                </div>
                <div class="p-5 sm:p-6">
                    <p class="text-sm leading-6 text-muted">{{ __('Project access requires an active project membership. Product grants and subscriptions are managed separately for each app.') }}</p>
                    <p class="mt-4 text-xs text-muted">{{ __('Project created :date', ['date' => $project->created_at->toFormattedDateString()]) }}</p>
                </div>
            </x-signal.ui.card>
        </section>
    </div>
</x-signal.layouts.platform>
