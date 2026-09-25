@php
    $products = [
        'deployer' => __('Deployer'),
        'monitor' => __('Monitor'),
        'analytics' => __('Analytics'),
    ];
    $projectNavigationItems = [
        ['label' => __('Overview'), 'href' => route('core.projects.show', [$workspace, $project]), 'active' => ['core.projects.show']],
        ['label' => __('Resources'), 'href' => '#resources'],
        ['label' => __('Connections'), 'href' => '#connections'],
        ['label' => __('Team access'), 'href' => '#team-access'],
    ];
    if ($projectSetupSteps->isNotEmpty()) {
        $projectNavigationItems[] = ['label' => __('Setup'), 'href' => '#setup'];
    }
    $navigation = [
        'groups' => [[
            'label' => __('Project'),
            'items' => $projectNavigationItems,
        ]],
    ];
    $environmentOptions = $project->environments->map(fn ($environment): array => [
        'id' => $environment->getKey(),
        'name' => $environment->name,
        'href' => route('core.projects.show', [$workspace, $project, 'context_environment' => $environment->getKey()]).'#environment-'.$environment->getKey(),
    ]);
    $environmentIndexUrl = route('core.projects.show', [$workspace, $project]);
    $productContextLinks = $productContextNavigation['links'];
    $productContextStatus = $productContextNavigation['status'];
    $productUrlOverrides = $productContextNavigation['urls'];
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
    :current-environment="$environmentContext->environment"
    :environment-context-unavailable="$environmentContext->isUnavailable()"
    :environment-options="$environmentOptions"
    :product-url-overrides="$productUrlOverrides"
    :show-environment-context="true"
    :environment-index-url="$environmentIndexUrl"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name.' · '.__('Project overview')"
        :title="$project->name"
        :description="$project->description ?? __('One shared project for your connected applications.')"
    >
        <x-slot:actions>
            @if ($canManageProjects)
                <x-signal.ui.button :href="route('core.projects.handover.export', [$workspace, $project])">
                    {{ __('Export manifest') }}
                </x-signal.ui.button>
                <x-signal.ui.button :href="route('core.projects.edit', [$workspace, $project])">
                    <svg class="h-4 w-4 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#pencil"></use></svg>
                    {{ __('Edit project') }}
                </x-signal.ui.button>
            @endif
            <x-signal.ui.button :href="route('core.projects.index', $workspace)">
                <svg class="h-4 w-4 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#view-grid"></use></svg>
                {{ __('All projects') }}
            </x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if ($environmentContext->isUnavailable())
        <x-signal.ui.alert tone="warning" class="mb-6" role="alert">
            {{ __('The selected shared environment is missing, inactive, or belongs to another project. No app activity or setup status is shown until you choose an active environment.') }}
        </x-signal.ui.alert>
    @endif

    @if ($environmentContext->isSelected())
        <p class="mb-6 text-sm text-muted" role="status">
            {{ __('Activity and setup use :environment. Project resources, subscriptions, and connection operations keep their own explicit scope.', ['environment' => $environmentContext->environment->name]) }}
        </p>
    @endif

    <section aria-label="{{ __('Connected products') }}" class="mb-8 grid gap-4 lg:grid-cols-3">
        @foreach ($products as $key => $label)
            @php
                $subscription = $subscriptions->get($key)?->subscription;
                $product = $project->products->firstWhere('product', $key);
                $hasAccess = $productGrants->has($key);
                $isConnected = $product !== null && $product->status === 'active';
                $productUrl = $environmentContext->isSelected()
                    ? ($productContextLinks[$key] ?? null)
                    : ($environmentContext->isUnavailable() ? null : ($productLinks[$key] ?? null));
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
                @if ($environmentContext->isSelected() && $hasAccess && $isConnected && filled($productContextStatus[$key] ?? null))
                    <p class="mt-3 text-xs leading-5 text-muted">{{ $productContextStatus[$key] }}</p>
                @endif
                @if ($isConnected && $productUrl)
                    <a href="{{ $productUrl }}" class="mt-5 inline-flex min-h-9 items-center gap-2 rounded-control px-3 text-sm font-extrabold text-primary hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                        {{ __('Open :product', ['product' => $label]) }}
                        <svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#arrow-up-right"></use></svg>
                    </a>
                @elseif ($environmentContext->isUnavailable() && $hasAccess && $isConnected)
                    <p class="mt-5 text-xs leading-5 text-muted">{{ __('Choose an active shared environment to open this app in the matching context.') }}</p>
                @elseif ($environmentContext->isSelected() && $hasAccess && $isConnected)
                    <a href="#link-existing-resources-heading" class="mt-5 inline-flex min-h-9 items-center gap-2 rounded-control px-3 text-sm font-extrabold text-primary hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                        {{ __('Map :product environment', ['product' => $label]) }}
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
                <x-signal.ui.project-product-summary :summary="$summary" :product-label="$products[$key] ?? str($key)->headline()" />
            @endforeach
        </section>
    @endif

    @if ($projectSetupSteps->isNotEmpty())
        <section id="setup" aria-labelledby="project-setup-heading" class="mb-8">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Guided setup') }}</p>
                    <h2 id="project-setup-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Get this project ready') }}</h2>
                </div>
                <p class="text-sm text-muted">{{ __('Progress follows verified app data and resumes when you return.') }}</p>
            </div>
            <div class="grid gap-4 lg:grid-cols-3">
                @foreach ($projectSetupSteps->groupBy('product') as $productKey => $steps)
                    <x-signal.ui.card class="p-5">
                        <h3 class="text-base font-extrabold text-ink">{{ $products[$productKey] ?? str($productKey)->headline() }}</h3>
                        <ol class="mt-4 divide-y divide-line">
                            @foreach ($steps as $step)
                                @php
                                    [$stepTone, $stepLabel] = match ($step->state) {
                                        \App\Core\Data\Projects\ProjectSetupStepState::Complete => ['success', __('Complete')],
                                        \App\Core\Data\Projects\ProjectSetupStepState::NeedsAction => ['warning', __('Next step')],
                                        \App\Core\Data\Projects\ProjectSetupStepState::Unavailable => ['neutral', __('Unavailable')],
                                    };
                                @endphp
                                <li class="py-4 first:pt-0 last:pb-0">
                                    @if ($step->contextName)
                                        <p class="mb-1 text-[10px] font-extrabold uppercase tracking-[0.14em] text-subtle">
                                            @if ($step->contextLabel){{ $step->contextLabel }} · @endif{{ $step->contextName }}
                                        </p>
                                    @endif
                                    <div class="flex items-start justify-between gap-3">
                                        <h4 class="text-sm font-extrabold text-ink">{{ $step->title }}</h4>
                                        <x-signal.ui.badge :tone="$stepTone">{{ $stepLabel }}</x-signal.ui.badge>
                                    </div>
                                    <p class="mt-2 text-sm leading-6 text-muted">{{ $step->detail }}</p>
                                    @if ($step->url && $step->actionLabel)
                                        <x-signal.ui.link :href="$step->url" size="sm" class="mt-3">
                                            {{ $step->actionLabel }}
                                            <svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#arrow-up-right"></use></svg>
                                        </x-signal.ui.link>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </x-signal.ui.card>
                @endforeach
            </div>
        </section>
    @endif

    @if ($project->environments->isNotEmpty() || $canManageConnections)
        <section id="environments" aria-labelledby="project-environments-heading" class="mb-8">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Shared project context') }}</p>
                    <h2 id="project-environments-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Project environments') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Link each app environment to its matching shared environment. Missing mappings never default to Production.') }}</p>
                </div>
                @if ($canManageConnections)
                    <x-signal.ui.menu align="right" trigger-class="ui-btn ui-btn-secondary ui-btn-sm items-center" panel-class="w-[min(30rem,calc(100vw-2rem))] p-0" data-signal-menu>
                        <x-slot:trigger>
                            <svg class="h-4 w-4 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#plus"></use></svg>
                            {{ __('Add shared environment') }}
                        </x-slot:trigger>
                        <x-signal.ui.card class="p-4 sm:p-5">
                            <form method="POST" action="{{ route('core.projects.environments.store', [$workspace, $project]) }}" class="grid gap-4">
                                @csrf
                                <x-signal.ui.field :label="__('Environment name')" name="name">
                                    <x-signal.ui.input name="name" :value="old('name')" maxlength="80" required />
                                </x-signal.ui.field>
                                <x-signal.ui.field :label="__('Environment type')" name="environment_type" :hint="__('This labels the shared context; it does not connect an app environment automatically.')">
                                    <x-signal.ui.select name="environment_type" required>
                                        <option value="production" @selected(old('environment_type', 'production') === 'production')>{{ __('Production') }}</option>
                                        <option value="staging" @selected(old('environment_type') === 'staging')>{{ __('Staging') }}</option>
                                        <option value="preview" @selected(old('environment_type') === 'preview')>{{ __('Preview') }}</option>
                                        <option value="development" @selected(old('environment_type') === 'development')>{{ __('Development') }}</option>
                                        <option value="custom" @selected(old('environment_type') === 'custom')>{{ __('Custom') }}</option>
                                    </x-signal.ui.select>
                                </x-signal.ui.field>
                                <x-signal.ui.button variant="primary" type="submit" class="justify-center">{{ __('Create environment') }}</x-signal.ui.button>
                            </form>
                        </x-signal.ui.card>
                    </x-signal.ui.menu>
                @endif
            </div>

            @if ($project->environments->isEmpty())
                <x-signal.ui.empty-state
                    :title="__('No shared environments yet')"
                    :description="__('Create a shared environment, then map each Deployer or Monitor environment to it from the existing-resource form.')"
                    icon="globe"
                />
            @else
                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach ($project->environments as $environment)
                        <x-signal.ui.card class="p-5" id="environment-{{ $environment->getKey() }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="ui-eyebrow">{{ str($environment->environment_type)->headline() }}</p>
                                    <h3 class="mt-1 text-base font-extrabold text-ink">{{ $environment->name }}</h3>
                                    @if (data_get($environment->metadata, 'branch'))
                                        <p class="mt-1 font-mono text-xs text-muted">{{ data_get($environment->metadata, 'branch') }}</p>
                                    @endif
                                </div>
                                <x-signal.ui.badge :tone="in_array($environment->status, ['ready', 'active', 'running'], true) ? 'success' : (in_array($environment->status, ['failed', 'error'], true) ? 'danger' : 'warning')">
                                    {{ str($environment->status)->headline() }}
                                </x-signal.ui.badge>
                            </div>
                            @if ($environment->resources->isNotEmpty())
                                <ul class="mt-4 grid gap-2 border-t border-line pt-4" aria-label="{{ __('Mapped app environments for :environment', ['environment' => $environment->name]) }}">
                                    @foreach ($environment->resources as $resource)
                                        <li class="flex items-center justify-between gap-3 text-sm">
                                            <span class="truncate font-bold text-ink">{{ $resource->name ?: str($resource->resource_type)->headline() }}</span>
                                            <span class="shrink-0 text-xs text-muted">{{ $products[$resource->product] ?? str($resource->product)->headline() }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="mt-4 border-t border-line pt-4 text-sm text-muted">{{ __('No app environments are mapped here yet.') }}</p>
                            @endif
                        </x-signal.ui.card>
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    @if ($canManageConnections && $resourceCandidates->isNotEmpty())
        <section aria-labelledby="link-existing-resources-heading" class="mb-8">
            <div class="mb-4">
                <p class="ui-eyebrow">{{ __('Keep your existing setup') }}</p>
                <h2 id="link-existing-resources-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Link an existing resource') }}</h2>
                <p class="mt-1 max-w-3xl text-sm leading-6 text-muted">{{ __('Choose an app resource you already manage. It will join this shared project without creating a duplicate.') }}</p>
            </div>
            <div class="grid gap-4 lg:grid-cols-3">
                @foreach ($resourceCandidates as $productKey => $candidates)
                    <x-signal.ui.card class="p-5">
                        <form method="POST" action="{{ route('core.projects.resources.store', [$workspace, $project]) }}" class="grid gap-4">
                            @csrf
                            <x-signal.ui.input type="hidden" name="product" :value="$productKey" :restore="false" />
                            <x-signal.ui.field
                                :label="__('Choose :product resource', ['product' => $products[$productKey] ?? str($productKey)->headline()])"
                                name="resource_id"
                                :description="__('Existing resources stay in their current app database and retain their current settings.')"
                            >
                                <x-signal.ui.select name="resource_id" required>
                                    <option value="">{{ __('Select a resource') }}</option>
                                    @foreach ($candidates as $candidate)
                                        <option value="{{ $candidate->selectionKey() }}" @selected(old('resource_id') === $candidate->selectionKey())>
                                            {{ str($candidate->resourceType)->headline() }} · {{ $candidate->name }}{{ $candidate->detail ? ' · '.$candidate->detail : '' }}
                                        </option>
                                    @endforeach
                                </x-signal.ui.select>
                            </x-signal.ui.field>
                            <x-signal.ui.field
                                :label="__('Map to project environment')"
                                name="environment_id"
                                :description="$project->environments->isEmpty()
                                    ? __('App environments need a shared project environment first. Create one above; project-level resources can be linked without a mapping.')
                                    : __('Required for individual app environments. Leave blank for a project-level resource.')"
                            >
                                <x-signal.ui.select name="environment_id" :disabled="$project->environments->isEmpty()">
                                    @if ($project->environments->isEmpty())
                                        <option value="">{{ __('No shared environments yet') }}</option>
                                    @else
                                        <option value="">{{ __('Project-level · no environment mapping') }}</option>
                                        @foreach ($project->environments as $environment)
                                            @if ($environment->status === 'active')
                                                <option value="{{ $environment->getKey() }}" @selected((string) old('environment_id') === (string) $environment->getKey())>
                                                    {{ $environment->name }}
                                                </option>
                                            @endif
                                        @endforeach
                                    @endif
                                </x-signal.ui.select>
                            </x-signal.ui.field>
                            <x-signal.ui.button variant="primary" type="submit" class="justify-center">
                                {{ __('Link :product resource', ['product' => $products[$productKey] ?? str($productKey)->headline()]) }}
                            </x-signal.ui.button>
                        </form>
                    </x-signal.ui.card>
                @endforeach
            </div>
        </section>
    @endif

    <section id="resources" aria-labelledby="project-resources-heading" class="mb-8">
        <div class="mb-4">
            <p class="ui-eyebrow">{{ __('Shared project context') }}</p>
            <h2 id="project-resources-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Project resources') }}</h2>
        </div>

        <x-signal.ui.project-resource-map
            :products="$products"
            :resources="$project->resources"
            :connections="$projectConnections"
            :resource-destinations="$resourceDestinations"
            :hidden-connection-count="$hiddenConnectionCount"
            class="mb-4"
        />

        <x-signal.ui.card>
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                <div>
                    <h3 class="text-base font-extrabold text-ink">{{ __('Resource inventory') }}</h3>
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
                            <p class="max-w-xl text-xs leading-5 text-muted">{{ __('Deployment and incident annotations are delivered as product events. Analytics traffic context is read-only and shown during Monitor investigations. Product data and subscriptions remain separate.') }}</p>
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

                    @if ($projectWorkflowRuns->isNotEmpty())
                        <section class="mb-6" aria-labelledby="project-workflow-runs-heading">
                            <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                                <div>
                                    <p class="ui-eyebrow">{{ __('Delivery progress') }}</p>
                                    <h3 id="project-workflow-runs-heading" class="mt-1 text-sm font-extrabold text-ink">{{ __('Recent workflow runs') }}</h3>
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <p class="text-xs text-muted">{{ __('Source success stays recorded if a connected step needs attention.') }}</p>
                                    <x-signal.ui.link :href="route('core.workspace.workflows', $workspace)" size="sm">
                                        {{ __('All workflow activity') }}
                                    </x-signal.ui.link>
                                </div>
                            </div>
                            <div class="grid gap-3">
                                @foreach ($projectWorkflowRuns as $workflowRun)
                                    <x-signal.ui.workflow-run :run="$workflowRun" />
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if ($projectConnections->isEmpty())
                        @if ($hiddenConnectionCount > 0)
                            <p class="text-sm leading-6 text-muted">{{ __('Configured workflows are hidden until access to both connected resources can be confirmed.') }}</p>
                        @else
                            <p class="text-sm leading-6 text-muted">{{ __('No application connections have been configured for this project.') }}</p>
                        @endif
                    @else
                        <ul class="divide-y divide-line">
                            @foreach ($projectConnections as $connection)
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
                                        <x-signal.ui.project-connection-diagnostic
                                            :diagnostic="$connectionDiagnostics[(string) $connection->getKey()]"
                                            class="mt-3"
                                        />
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
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <x-signal.ui.badge :tone="in_array($connection->status, ['active', 'connected'], true) ? 'success' : (in_array($connection->status, ['failed', 'error'], true) ? 'danger' : 'warning')">{{ $connection->status === 'pending' ? __('Setup pending') : str($connection->status)->headline() }}</x-signal.ui.badge>
                                        @if ($connection->automation_paused_at)
                                            <x-signal.ui.badge tone="warning">{{ __('Automation paused') }}</x-signal.ui.badge>
                                        @endif
                                        @if ($canManageConnections)
                                            @if (collect((array) $connection->capabilities)->contains(fn (string $capability): bool => \App\Core\Enums\ProjectConnectionCapability::tryFrom($capability)?->hasDeliveryHandler() ?? false))
                                                <form method="POST" action="{{ route('core.projects.connections.automation', [$workspace, $project, $connection]) }}">
                                                    @csrf
                                                    <x-signal.ui.input type="hidden" name="paused" :value="$connection->automation_paused_at ? '0' : '1'" :restore="false" />
                                                    <x-signal.ui.button type="submit" class="min-h-8 px-3 text-xs">{{ $connection->automation_paused_at ? __('Resume automation') : __('Pause automation') }}</x-signal.ui.button>
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
