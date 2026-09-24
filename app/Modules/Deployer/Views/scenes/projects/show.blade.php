<x-layouts.app>
    @php
        $projectPageUrl = request()->fullUrlWithoutQuery(['dialog', 'configuration_review']);
        $repositoryCreateOpen = request()->query('dialog') === 'create-repository';
        $websiteCreateOpen = request()->query('dialog') === 'create-website';
        $configurationDialogOpen = request()->query('dialog') === 'application-configuration';
        $configurationReviewId = request()->integer('configuration_review');
        $configurationDialogUrl = (string) \Illuminate\Support\Uri::of($projectPageUrl)->withQuery([
            'dialog' => 'application-configuration',
            ...($configurationReviewId > 0 ? ['configuration_review' => $configurationReviewId] : []),
        ]);
        $configurationDialogContentUrl = route('projects.configuration.dialog', [
            'project' => $project,
            ...($configurationReviewId > 0 ? ['configuration_review' => $configurationReviewId] : []),
        ]);
        $previewSettingsDialogId = 'project-preview-settings-dialog';
        $previewSettingsDialogOpen = $canManage && $featureAccess['previews']
            && (request()->query('dialog') === 'preview-settings' || old('_project_form') === 'previews');
        $previewSettingsDialogUrl = route('projects.show', ['project' => $project, 'dialog' => 'preview-settings']);
    @endphp

    <x-layouts.partials.breadcrumbs :route="route('projects.index')" :title="__('Back to applications')" />

    <x-signal.ui.page-header icon="view-grid" :title="$project->name" :description="$project->description ?: __('Application environments and resources.')">
        <x-slot:actions>
            @if($project->environments->isNotEmpty())
                <x-signal.overlays.side-sheet-trigger sheet="project-environment-navigation" size="sm">
                    {{ __('Browse environments') }}
                </x-signal.overlays.side-sheet-trigger>
            @endif
            @if($canManage)
                <x-signal.ui.button
                    :href="$configurationDialogUrl"
                    data-modal-trigger="application-configuration-dialog"
                    data-modal-content-url="{{ $configurationDialogContentUrl }}"
                    aria-controls="application-configuration-dialog"
                    aria-expanded="{{ $configurationDialogOpen ? 'true' : 'false' }}"
                    variant="secondary"
                >{{ __('Configuration as code') }}</x-signal.ui.button>
                <form method="POST" action="{{ route('projects.destroy', $project) }}">
                    @csrf
                    @method('DELETE')
                    <x-signal.ui.button type="submit" variant="danger">{{ __('Delete application') }}</x-signal.ui.button>
                </form>
            @endif
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if($project->environments->isNotEmpty())
        <x-signal.overlays.side-sheet
            id="project-environment-navigation"
            :eyebrow="$project->name"
            :title="__('Environments')"
            :description="__('Jump to an environment and continue managing its deployment, configuration, and runtime.')"
        >
            <nav class="grid gap-2" aria-label="{{ __('Project environments') }}">
                @foreach($project->environments as $environment)
                    <a
                        href="#environment-{{ $environment->id }}-heading"
                        class="ui-card flex min-w-0 items-center justify-between gap-4 p-4 transition hover:border-primary/50"
                        data-sheet-close
                    >
                        <span class="min-w-0">
                            <strong class="block truncate text-sm text-ink">{{ $environment->name }}</strong>
                            <span class="mt-1 block truncate text-xs text-muted">{{ $environment->branch }} · {{ ucfirst($environment->type) }}</span>
                        </span>
                        <x-signal.ui.badge :tone="$environment->hibernated_at ? 'neutral' : 'success'">
                            {{ $environment->hibernated_at ? __('Hibernated') : __('Available') }}
                        </x-signal.ui.badge>
                    </a>
                @endforeach
            </nav>
        </x-signal.overlays.side-sheet>
    @endif

    <x-signal.ui.insights
        id="project-insights"
        class="mt-8"
        :summary="trans_choice(':count environment|:count environments', $project->environments->count(), ['count' => $project->environments->count()])"
        :mobile-open="true"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('Application summary') }}">
            <x-signal.ui.stat
                :label="__('Environments')"
                :value="$project->environments->count()"
                :description="__('Targets managed by this application.')"
            />
            <x-signal.ui.stat
                :label="__('Attached sites')"
                :value="$project->environments->whereNotNull('website_id')->count()"
                :description="__('Environments connected to a website.')"
            />
            <x-signal.ui.stat
                :label="__('Runtime')"
                :value="$project->environments->contains(fn ($environment) => $environment->hibernated_at) ? __('Partially hibernated') : __('Running')"
                :description="__('Current environment runtime state.')"
            />
            <x-signal.ui.stat
                :label="__('Previews')"
                :value="$project->preview_enabled ? __('Enabled') : __('Disabled')"
                :description="__('Pull-request preview environments.')"
            />
        </dl>
        <x-signal.ui.local-nav class="mt-5" :label="__('Application sections')">
            <a href="#project-environments" class="ui-local-nav__link">{{ __('Environments') }}</a>
            <a href="#add-environment" class="ui-local-nav__link">{{ __('Add environment') }}</a>
            <a href="#preview-environments" class="ui-local-nav__link">{{ __('Previews') }}</a>
        </x-signal.ui.local-nav>
    </x-signal.ui.insights>

    <div id="project-environments" class="mt-5 scroll-mt-24 space-y-4" data-project-environments>
        @foreach($project->environments as $environment)
            @php
                $environmentRepositories = $environment->website?->repositories ?? collect();
                $repository = $environmentRepositories->firstWhere('branch', $environment->branch);
                $deploymentInProgress = $environmentRepositories->contains(fn ($source) => $source->latestBuild && in_array($source->latestBuild->status, \App\Modules\Deployer\Models\Build::ACTIVE_STATUSES, true));
                $deploymentReady = $repository?->isDeploymentReady() === true;
                $successfulBuild = $repository?->latestSuccessfulBuild;
                if ($successfulBuild?->environment_id !== $environment->id) $successfulBuild = null;
                $environmentRanks = ['preview' => 0, 'development' => 1, 'staging' => 2, 'production' => 3];
                $promotionTargets = $project->environments->filter(function ($candidate) use ($environmentRanks, $environment, $repository) {
                    $targetRepository = $candidate->website?->repositories->firstWhere('branch', $candidate->branch);
                    return ($environmentRanks[$candidate->type] ?? -1) > ($environmentRanks[$environment->type] ?? -1)
                        && $repository && $targetRepository
                        && strtolower(rtrim($repository->url, '/')) === strtolower(rtrim($targetRepository->url, '/'))
                        && $repository->provider?->provider === $targetRepository->provider?->provider;
                });
                $environmentErrorPanel = (string) old('_environment_id') === (string) $environment->id
                    ? (string) old('_environment_panel')
                    : null;
                $environmentSettingsOpen = $environmentErrorPanel === 'settings';
                $deploymentControlsOpen = $environmentErrorPanel === 'deployment-controls';
                $variablesOpen = $environmentErrorPanel === 'variables';
                $processesOpen = $environmentErrorPanel === 'processes';
                $resourcesOpen = $environmentErrorPanel === 'resources';
                $canUpdateEnvironment = auth()->user()?->can('update', $environment) ?? false;
                $environmentSettingsDialogId = 'environment-settings-dialog-'.$environment->id;
                $environmentSettingsDialogKey = 'edit-environment-settings-'.$environment->id;
                $environmentSettingsDialogOpen = $canUpdateEnvironment
                    && ($environmentSettingsOpen || request()->query('dialog') === $environmentSettingsDialogKey);
                $environmentSettingsDialogUrl = route('projects.show', ['project' => $project, 'dialog' => $environmentSettingsDialogKey]);
                $deploymentControlsDialogId = 'environment-deployment-controls-dialog-'.$environment->id;
                $deploymentControlsDialogKey = 'edit-deployment-controls-'.$environment->id;
                $deploymentControlsDialogOpen = $canUpdateEnvironment
                    && ($deploymentControlsOpen || request()->query('dialog') === $deploymentControlsDialogKey);
                $deploymentControlsDialogUrl = route('projects.show', ['project' => $project, 'dialog' => $deploymentControlsDialogKey]);
                $variableDialogId = 'environment-variable-dialog-'.$environment->id;
                $variableDialogKey = 'add-variable-'.$environment->id;
                $variableDialogOpen = $canUpdateEnvironment && ($variablesOpen || request()->query('dialog') === $variableDialogKey);
                $variableDialogUrl = route('projects.show', ['project' => $project, 'dialog' => $variableDialogKey]);
                $processDialogId = 'environment-process-dialog-'.$environment->id;
                $processDialogKey = 'add-process-'.$environment->id;
                $processDialogOpen = $canUpdateEnvironment && $featureAccess['workers'] && ($processesOpen || request()->query('dialog') === $processDialogKey);
                $processDialogUrl = route('projects.show', ['project' => $project, 'dialog' => $processDialogKey]);
                $resourceDialogId = 'environment-resource-dialog-'.$environment->id;
                $resourceDialogKey = 'add-resource-'.$environment->id;
                $resourceDialogOpen = $canUpdateEnvironment && $featureAccess['resources'] && ($resourcesOpen || request()->query('dialog') === $resourceDialogKey);
                $resourceDialogUrl = route('projects.show', ['project' => $project, 'dialog' => $resourceDialogKey]);
                $environmentRepositoryCreateUrl = (string) \Illuminate\Support\Uri::of($projectPageUrl)->withQuery([
                    'dialog' => 'create-repository',
                    'website_id' => $environment->website_id,
                    'branch' => $environment->branch,
                ]);
                $environmentRepositoryCreateContentUrl = route('dialogs.create', [
                    'resource' => 'repository',
                    'return_to' => $projectPageUrl,
                    'website_id' => $environment->website_id,
                    'branch' => $environment->branch,
                ]);
                $environmentWebsiteCreateUrl = (string) \Illuminate\Support\Uri::of($projectPageUrl)->withQuery(['dialog' => 'create-website']);
                $environmentWebsiteCreateContentUrl = route('dialogs.create', [
                    'resource' => 'website',
                    'return_to' => $projectPageUrl,
                ]);
            @endphp
            <x-signal.ui.panel as="section" class="ui-panel overflow-hidden" aria-labelledby="environment-{{ $environment->id }}-heading" data-project-environment>
                <div class="flex flex-wrap items-center gap-4 border-b border-line px-5 py-4">
                    <x-signal.ui.avatar :name="$environment->name" class="ui-avatar-md shrink-0" />
                    <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><h2 id="environment-{{ $environment->id }}-heading" class="text-lg font-extrabold text-ink">{{ $environment->name }}</h2>@if($environment->is_protected)<x-signal.ui.badge tone="accent">{{ __('Protected') }}</x-signal.ui.badge>@endif @if($environment->hibernated_at)<x-signal.ui.badge tone="neutral">{{ __('Hibernated') }}</x-signal.ui.badge>@endif</div><p class="mt-0.5 truncate font-mono text-xs text-muted">{{ $environment->branch }} · {{ ucfirst($environment->type) }}</p></div>
                    <div class="flex w-full flex-wrap gap-2 text-xs sm:w-auto"><span class="rounded-control border border-line bg-surface-muted px-3 py-2 text-muted">{{ $environment->server?->label ?? __('No server') }}</span><span class="rounded-control border border-line bg-surface-muted px-3 py-2 text-muted">{{ $environment->website?->name ?? __('No site') }}</span><span class="rounded-control border border-line bg-surface-muted px-3 py-2 font-bold text-muted">{{ $environment->minimum_replicas }}–{{ $environment->maximum_replicas }}×</span><x-signal.ui.button :href="route('observability.environments.context', $environment)" variant="secondary">{{ __('Investigate evidence') }}</x-signal.ui.button></div>
                </div>

                @if(!$repository?->builds()->where('status', \App\Modules\Deployer\Models\Build::STATUS_SUCCEEDED)->exists())
                    @php
                        $readiness = [
                        ['label' => __('Active server attached'), 'ready' => $environment->server?->provisioning_status === \App\Modules\Deployer\Models\Server::STATUS_ACTIVE, 'url' => route('servers.index')],
                        ['label' => __('Active website attached'), 'ready' => $environment->website?->provisioning_status === \App\Modules\Deployer\Models\Website::STATUS_ACTIVE, 'url' => route('websites.index')],
                        ['label' => __('Repository and branch connected'), 'ready' => (bool) $repository, 'url' => $environment->website ? $environmentRepositoryCreateUrl : $environmentWebsiteCreateUrl, 'modal' => $environment->website ? 'repository-create-dialog' : 'website-create-dialog', 'content_url' => $environment->website ? $environmentRepositoryCreateContentUrl : $environmentWebsiteCreateContentUrl],
                        ['label' => __('Provider credentials available'), 'ready' => (bool) $repository?->provider_id, 'url' => route('providers.index')],
                        ];
                        $readyCount = collect($readiness)->where('ready', true)->count();
                    @endphp
                    <x-signal.ui.panel as="aside" class="ui-panel rounded-none border-x-0 border-t-0 border-l-4 border-line bg-surface-muted px-5 py-4" style="border-left-color: var(--ui-primary)" aria-label="{{ __('First deployment readiness') }}">
                        <div class="flex flex-wrap items-center justify-between gap-3"><div><p class="font-bold text-ink">{{ __('First deployment readiness') }}</p><p class="mt-1 text-xs text-muted">{{ __(':ready of :total checks passed. Deployment stays disabled until every dependency is active.', ['ready' => $readyCount, 'total' => count($readiness)]) }}</p></div><a href="{{ route('docs') }}#first-deploy" class="ui-link text-xs underline">{{ __('Open setup guide') }}</a></div>
                        <ul class="mt-3 grid gap-2 sm:grid-cols-2">@foreach($readiness as $check)<li class="flex items-center gap-2 text-xs text-muted"><span aria-hidden="true" class="font-extrabold {{ $check['ready'] ? 'text-success' : 'text-muted' }}">{{ $check['ready'] ? '✓' : '○' }}</span>@if($check['ready'])<span>{{ $check['label'] }}</span>@else<a href="{{ $check['url'] }}" @if(isset($check['modal'])) data-modal-trigger="{{ $check['modal'] }}" data-modal-content-url="{{ $check['content_url'] }}" aria-controls="{{ $check['modal'] }}" aria-expanded="{{ ($check['modal'] === 'repository-create-dialog' ? $repositoryCreateOpen : $websiteCreateOpen) ? 'true' : 'false' }}" @endif class="ui-link underline">{{ $check['label'] }}</a>@endif</li>@endforeach</ul>
                    </x-signal.ui.panel>
                @endif

                <div class="flex flex-wrap items-center gap-3 border-b border-line bg-surface-muted px-5 py-3">
                    <div class="min-w-0 flex-1">
                        @if($repository)
                            <p class="truncate text-sm font-bold text-ink">{{ $repository->name }} <span class="font-mono font-normal text-muted">· {{ $repository->branch }}</span></p>
                            <p class="mt-0.5 text-xs text-muted">@if($repository->latestBuild){{ __('Latest deployment: :status', ['status' => str($repository->latestBuild->status)->replace('_', ' ')]) }}@else{{ __('Ready for the first deployment') }}@endif</p>
                        @elseif($environment->website)
                            <p class="text-sm font-bold text-ink">{{ __('Connect source control') }}</p><p class="mt-0.5 text-xs text-muted">{{ __('Attach a repository to complete this environment.') }}</p>
                        @else
                            <p class="text-sm font-bold text-ink">{{ __('Attach infrastructure') }}</p><p class="mt-0.5 text-xs text-muted">{{ __('Select a ready server and website before connecting source control.') }}</p>
                        @endif
                    </div>
                    @if($repository)
                        <x-signal.ui.button :href="route('repositories.show', $repository)" variant="secondary">{{ __('View source') }}</x-signal.ui.button>
                        @if($canDeploy)<form method="POST" action="{{ route('repositories.deploy', $repository) }}">@csrf<x-signal.ui.button type="submit" variant="primary" :disabled="! $deploymentReady || $deploymentInProgress">{{ $deploymentInProgress ? __('Deploying…') : ($deploymentReady ? __('Deploy now') : __('Not ready')) }}</x-signal.ui.button></form>@endif
                    @elseif($environment->website && $canDeploy)
                        <x-signal.ui.button :href="$environmentRepositoryCreateUrl" data-modal-trigger="repository-create-dialog" data-modal-content-url="{{ $environmentRepositoryCreateContentUrl }}" aria-controls="repository-create-dialog" aria-expanded="{{ $repositoryCreateOpen ? 'true' : 'false' }}" variant="primary">{{ __('Connect repository') }}</x-signal.ui.button>
                    @elseif($canDeploy)
                        <x-signal.ui.button :href="$environmentWebsiteCreateUrl" data-modal-trigger="website-create-dialog" data-modal-content-url="{{ $environmentWebsiteCreateContentUrl }}" aria-controls="website-create-dialog" aria-expanded="{{ $websiteCreateOpen ? 'true' : 'false' }}" variant="primary">{{ __('Create website') }}</x-signal.ui.button>
                    @endif
                </div>

                @if($canDeploy && $successfulBuild && $successfulBuild->revision && $promotionTargets->isNotEmpty())
                    @php
                        $promotionDialogId = 'promotion-dialog-'.$successfulBuild->id;
                        $promotionDialogHasErrors = old('_promotion_build_id') == $successfulBuild->id
                            && $errors->hasAny(['target_environment_id', 'promotion_note']);
                        $promotionDialogOpen = (request()->query('dialog') === 'promote'
                            && (string) request()->query('build_id') === (string) $successfulBuild->id
                            && ! session()->has('success')
                            && ! session()->has('error')
                            && ! session()->has('info')) || $promotionDialogHasErrors;
                        $promotionDialogUrl = route('projects.show', [
                            'project' => $project,
                            'dialog' => 'promote',
                            'build_id' => $successfulBuild->id,
                        ]);
                    @endphp
                    <x-signal.ui.panel as="aside" class="ui-panel rounded-none border-x-0 border-t-0 border-l-4 border-line bg-surface-muted px-5 py-4" style="border-left-color: var(--ui-primary)" data-project-promotion>
                        <div class="flex flex-wrap items-center gap-3"><div class="min-w-0 flex-1"><p class="font-bold text-ink">{{ __('Promote tested release') }}</p><p class="mt-1 text-xs text-muted">{{ __('Rebuild exact revision :revision with the target environment configuration. Target approval and maintenance policies still apply.', ['revision'=>$successfulBuild->shortRevision()]) }}</p></div><x-signal.ui.button href="{{ $promotionDialogUrl }}" data-modal-trigger="{{ $promotionDialogId }}" aria-controls="{{ $promotionDialogId }}" aria-expanded="{{ $promotionDialogOpen ? 'true' : 'false' }}" variant="primary">{{ __('Promote') }}</x-signal.ui.button></div>
                    </x-signal.ui.panel>
                    <x-scenes.projects.promotion-dialog
                        :build="$successfulBuild"
                        :open="$promotionDialogOpen"
                        :targets="$promotionTargets"
                    />
                @endif

                <div class="grid gap-px bg-surface-muted lg:grid-cols-3" data-project-runtime-controls>
                    <section id="environment-{{ $environment->id }}-settings" class="bg-surface p-5" aria-labelledby="environment-{{ $environment->id }}-settings-heading" data-project-settings>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 id="environment-{{ $environment->id }}-settings-heading" class="font-bold text-ink">{{ __('Environment settings') }}</h3>
                                <p class="mt-1 text-xs text-muted">{{ ucfirst($environment->runtime_type ?: 'php') }} · {{ $environment->branch }} · {{ $environment->website?->name ?? __('No website') }}</p>
                            </div>
                            @can('update', $environment)
                                <x-signal.ui.button :href="$environmentSettingsDialogUrl" data-modal-trigger="{{ $environmentSettingsDialogId }}" aria-controls="{{ $environmentSettingsDialogId }}" aria-expanded="{{ $environmentSettingsDialogOpen ? 'true' : 'false' }}" variant="secondary">{{ __('Edit settings') }}</x-signal.ui.button>
                            @endcan
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2 text-xs">
                            <x-signal.ui.badge :tone="$environment->is_protected ? 'accent' : 'neutral'">{{ $environment->is_protected ? __('Protected') : __('Unprotected') }}</x-signal.ui.badge>
                            <x-signal.ui.badge :tone="$environment->requires_deployment_approval ? 'accent' : 'neutral'">{{ $environment->requires_deployment_approval ? __('Approval required') : __('Auto deploy') }}</x-signal.ui.badge>
                            <span class="rounded-control border border-line bg-surface-muted px-3 py-2 text-muted">{{ $environment->server?->label ?? __('No server') }}</span>
                        </div>
                    </section>
                    @can('update', $environment)
                        <x-scenes.projects.environment-settings-dialog
                            :environment="$environment"
                            :servers="$servers"
                            :websites="$websites"
                            :feature-access="$featureAccess"
                            :open="$environmentSettingsDialogOpen"
                        />
                    @endcan

                    <section id="environment-{{ $environment->id }}-deployment-controls" class="bg-surface p-5" aria-labelledby="environment-{{ $environment->id }}-deployment-controls-heading" data-project-deployment-controls>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 id="environment-{{ $environment->id }}-deployment-controls-heading" class="font-bold text-ink">{{ __('Deployment controls') }}</h3>
                                <div class="mt-2 flex flex-wrap gap-2 text-xs"><x-signal.ui.badge :tone="$environment->deployment_locked_at ? 'danger' : 'success'">{{ $environment->deployment_locked_at ? __('Locked') : __('Unlocked') }}</x-signal.ui.badge>@if($environment->deployment_window_days)<x-signal.ui.badge tone="neutral">{{ __('Maintenance window active') }}</x-signal.ui.badge>@endif<x-signal.ui.badge tone="neutral">{{ str($environment->deployment_strategy ?: 'blue_green')->replace('_', ' ')->title() }}</x-signal.ui.badge></div>
                            </div>
                            @can('update', $environment)
                                <x-signal.ui.button :href="$deploymentControlsDialogUrl" data-modal-trigger="{{ $deploymentControlsDialogId }}" aria-controls="{{ $deploymentControlsDialogId }}" aria-expanded="{{ $deploymentControlsDialogOpen ? 'true' : 'false' }}" variant="secondary">{{ __('Edit controls') }}</x-signal.ui.button>
                            @endcan
                        </div>
                    </section>
                    @can('update', $environment)
                        <x-scenes.projects.deployment-controls-dialog
                            :environment="$environment"
                            :open="$deploymentControlsDialogOpen"
                        />
                    @endcan

                    <details id="environment-{{ $environment->id }}-runtime-capacity" class="group bg-surface p-5" data-project-runtime>
                        <summary class="flex cursor-pointer list-none items-center justify-between font-bold text-ink"><span>{{ __('Runtime capacity') }}</span><span class="text-muted group-open:rotate-45">+</span></summary>
                        <p class="mt-3 text-sm text-muted">{{ __('Pre-provision worker capacity and pause idle environments. Runtime changes are applied from Automation.') }}</p>
                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <label><span class="ui-label">{{ __('Minimum') }}</span><x-signal.ui.input value="{{ $environment->minimum_replicas }}" class="ui-input" disabled :restore="false" /></label>
                            <label><span class="ui-label">{{ __('Maximum') }}</span><x-signal.ui.input value="{{ $environment->maximum_replicas }}" class="ui-input" disabled :restore="false" /></label>
                            <label class="col-span-2"><span class="ui-label">{{ __('Idle hibernation') }}</span><x-signal.ui.input value="{{ $environment->hibernate_after_minutes ? __('After :minutes minutes', ['minutes' => $environment->hibernate_after_minutes]) : __('Never') }}" class="ui-input" disabled :restore="false" /></label>
                        </div>
                        @if($featureAccess['scaling'] || $featureAccess['hibernation'])<x-signal.ui.button :href="route('automation.index')" variant="secondary" class="mt-4">{{ __('Open automation') }}</x-signal.ui.button>@else<p class="mt-4 text-sm text-muted"><a href="{{ route('pricing') }}" class="ui-link">{{ __('View plans') }}</a> {{ __('to unlock runtime controls.') }}</p>@endif
                    </details>

                    <section id="environment-{{ $environment->id }}-variables" class="bg-surface p-5" aria-labelledby="environment-{{ $environment->id }}-variables-heading" data-project-variables>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 id="environment-{{ $environment->id }}-variables-heading" class="font-bold text-ink">{{ __('Encrypted variables') }} <span class="text-muted">({{ $environment->variables->count() }})</span></h3>
                                <p class="mt-1 text-xs text-muted">{{ __('Versioned values are encrypted and never shown on this page.') }}</p>
                            </div>
                            @can('update', $environment)
                                <x-signal.ui.button :href="$variableDialogUrl" data-modal-trigger="{{ $variableDialogId }}" aria-controls="{{ $variableDialogId }}" aria-expanded="{{ $variableDialogOpen ? 'true' : 'false' }}" variant="secondary">{{ __('Add variable') }}</x-signal.ui.button>
                            @endcan
                        </div>
                        <div class="mt-4 space-y-2">@forelse($environment->variables as $variable)<x-signal.ui.panel class="ui-panel flex items-center gap-2 bg-surface-muted px-3 py-2" data-project-variable><div class="min-w-0 flex-1"><code class="block truncate text-xs text-ink">{{ $variable->key }}</code><span class="text-[11px] text-muted">{{ str($variable->scope)->replace('_', ' ')->headline() }} · v{{ $variable->current_version }}@if($variable->rotation_due_at) · <span class="{{ $variable->rotation_due_at->isPast() ? 'text-danger' : '' }}">{{ __('rotate :date', ['date' => $variable->rotation_due_at->toDateString()]) }}</span>@endif</span></div><span class="text-xs text-muted">{{ $variable->is_secret ? '••••••••' : __('Encrypted') }}</span>@can('update', $environment)<form method="POST" action="{{ route('environments.variables.destroy', [$environment, $variable]) }}">@csrf @method('DELETE')<x-signal.ui.button variant="link" type="submit" class="ui-link text-xs">{{ __('Delete') }}</x-signal.ui.button></form>@endcan</x-signal.ui.panel>@empty<p class="text-sm text-muted">{{ __('No variables yet.') }}</p>@endforelse</div>
                        @can('update', $environment)
                            <x-scenes.projects.variable-create-dialog :environment="$environment" :open="$variableDialogOpen" />
                        @endcan
                    </section>
                </div>

                <div class="grid gap-5 border-t border-line p-5 lg:grid-cols-2">
                    <section id="environment-{{ $environment->id }}-processes" class="ui-panel bg-surface-muted p-4" aria-labelledby="environment-{{ $environment->id }}-processes-heading" data-project-processes>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 id="environment-{{ $environment->id }}-processes-heading" class="font-bold text-ink">{{ __('Workers and scheduler') }} <span class="text-muted">({{ $environment->processes->count() }})</span></h3>
                                <p class="mt-1 text-xs text-muted">{{ __('Encrypted commands are applied on the next deployment.') }}</p>
                            </div>
                            @can('update', $environment)
                                @if($featureAccess['workers'])
                                    <x-signal.ui.button :href="$processDialogUrl" data-modal-trigger="{{ $processDialogId }}" aria-controls="{{ $processDialogId }}" aria-expanded="{{ $processDialogOpen ? 'true' : 'false' }}" variant="secondary">{{ __('Add process') }}</x-signal.ui.button>
                                @endif
                            @endcan
                        </div>
                        <div class="mt-3 space-y-2">@foreach($environment->processes as $process)<x-signal.ui.panel class="ui-panel flex items-center gap-3 bg-surface p-3" data-project-process><div class="min-w-0 flex-1"><p class="font-bold text-ink">{{ $process->name }} <span class="font-normal text-muted">· {{ $process->type }} · ×{{ $process->replicas }}</span></p><p class="mt-1 text-xs text-muted">{{ __('Command encrypted · applied on next deployment') }}</p></div>@can('update', $environment)<form method="POST" action="{{ route('environments.processes.destroy', [$environment, $process]) }}">@csrf @method('DELETE')<x-signal.ui.button variant="link" type="submit" class="ui-link text-xs">{{ __('Delete') }}</x-signal.ui.button></form>@endcan</x-signal.ui.panel>@endforeach</div>
                        @can('update', $environment)
                            @if($featureAccess['workers'])
                                <x-scenes.projects.process-create-dialog :environment="$environment" :open="$processDialogOpen" />
                            @else
                                <p class="mt-4 text-sm text-muted">{{ __('Available on Starter and higher.') }} <a href="{{ route('pricing') }}" class="ui-link">{{ __('View plans') }}</a></p>
                            @endif
                        @endcan
                    </section>

                    <details id="environment-{{ $environment->id }}-resources" class="ui-panel group bg-surface-muted p-4" @if($resourcesOpen) open @endif data-project-resources>
                        <summary class="flex cursor-pointer list-none items-center justify-between font-bold text-ink"><span>{{ __('Attached resources') }} <span class="text-muted">({{ $environment->resources->count() }})</span></span><span class="text-muted group-open:rotate-45">+</span></summary>
                        <div class="mt-3 space-y-2">@foreach($environment->resources as $resource)<x-signal.ui.panel class="ui-panel flex items-center gap-3 bg-surface p-3" data-project-resource><div class="min-w-0 flex-1"><p class="font-bold text-ink">{{ $resource->name }}</p><p class="text-xs text-muted">{{ str($resource->type)->replace('_', ' ')->title() }} · {{ $resource->is_managed ? __('Managed') : __('External') }} · {{ ucfirst($resource->status) }}</p></div>@can('update', $environment)<form method="POST" action="{{ route('environments.resources.destroy', [$environment, $resource]) }}">@csrf @method('DELETE')<x-signal.ui.button variant="link" type="submit" class="ui-link text-xs">{{ __('Detach') }}</x-signal.ui.button></form>@endcan</x-signal.ui.panel>@endforeach</div>
                        @can('update', $environment)
                            @if($featureAccess['resources'])
                                <x-signal.ui.button :href="$resourceDialogUrl" data-modal-trigger="{{ $resourceDialogId }}" aria-controls="{{ $resourceDialogId }}" aria-expanded="{{ $resourceDialogOpen ? 'true' : 'false' }}" variant="secondary" class="mt-4">{{ __('Attach resource') }}</x-signal.ui.button>
                            @else
                                <p class="mt-4 text-sm text-muted">{{ __('Available on Pro and higher.') }} <a href="{{ route('pricing') }}" class="ui-link">{{ __('View plans') }}</a></p>
                            @endif
                        @endcan
                    </details>
                    @can('update', $environment)
                        @if($featureAccess['resources'])
                            <x-scenes.projects.resource-create-dialog :environment="$environment" :open="$resourceDialogOpen" />
                        @endif
                    @endcan
                </div>
            </x-signal.ui.panel>
        @endforeach
    </div>

    @php($addEnvironmentOpen = old('_environment_form') === 'add' || request()->query('dialog') === 'add-environment')
    @php($addEnvironmentDialogId = 'add-environment-dialog')
    @php($addEnvironmentDialogUrl = route('projects.show', ['project' => $project, 'dialog' => 'add-environment']))

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        @if($canDeploy)
            <x-signal.ui.panel as="section" id="add-environment" class="ui-panel scroll-mt-24 p-5" aria-labelledby="add-environment-heading" data-project-add-environment>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><p class="ui-eyebrow">{{ __('Runtime topology') }}</p><h2 id="add-environment-heading" class="mt-1 font-extrabold text-ink">{{ __('Add environment') }}</h2><p class="mt-2 text-sm text-muted">{{ __('Create a separate runtime for a branch, then attach infrastructure and source control.') }}</p></div>
                    <x-signal.ui.button :href="$addEnvironmentDialogUrl" data-modal-trigger="{{ $addEnvironmentDialogId }}" aria-controls="{{ $addEnvironmentDialogId }}" aria-expanded="{{ $addEnvironmentOpen ? 'true' : 'false' }}" variant="primary">{{ __('Add environment') }}</x-signal.ui.button>
                </div>
                <x-scenes.projects.environment-create-dialog :project="$project" :open="$addEnvironmentOpen" />
            </x-signal.ui.panel>
        @endif

        @php($previewPanelOpen = $project->previews->isNotEmpty() || old('_project_form') === 'previews')

        <x-signal.ui.panel as="details" id="preview-environments" class="ui-panel scroll-mt-24 p-5" :open="$previewPanelOpen" data-project-previews>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 font-extrabold text-ink"><span>{{ __('Preview environments') }} <span class="text-muted">({{ $project->previews->count() }})</span></span><x-signal.ui.badge :tone="$project->preview_enabled ? 'success' : 'neutral'">{{ $project->preview_enabled ? __('Enabled') : __('Disabled') }}</x-signal.ui.badge></summary>
            @if($canManage && $featureAccess['previews'])
                <x-signal.ui.button :href="$previewSettingsDialogUrl" data-modal-trigger="{{ $previewSettingsDialogId }}" aria-controls="{{ $previewSettingsDialogId }}" aria-expanded="{{ $previewSettingsDialogOpen ? 'true' : 'false' }}" variant="secondary" class="mt-4">{{ __('Configure previews') }}</x-signal.ui.button>
            @elseif($canManage)
                <p class="mt-4 text-sm text-muted">{{ __('Available on Pro and higher.') }} <a href="{{ route('pricing') }}" class="ui-link">{{ __('View plans') }}</a></p>
            @endif
            <div class="mt-4 space-y-2">
                @forelse($project->previews->sortByDesc('last_activity_at') as $preview)
                    @php($sourceSecrets = $preview->sourceEnvironment?->variables->where('is_secret', true)->whereIn('scope', ['runtime', 'all'])->whereNotIn('key', \App\Modules\Deployer\Models\PreviewSecretApproval::PROTECTED_KEYS) ?? collect())
                    @php($previewCleanup = $preview->stackCleanups->sortByDesc('id')->first())
                    <x-signal.ui.panel as="article" class="ui-panel bg-surface-muted p-3" data-project-preview>
                        <div class="flex items-center gap-3"><div class="min-w-0 flex-1"><p class="font-bold text-ink">#{{ $preview->pull_request_number }} · {{ $preview->title ?: $preview->source_branch }}</p><p class="truncate font-mono text-xs text-muted">{{ $preview->source_branch }} · {{ substr($preview->revision, 0, 12) }}</p></div><x-signal.ui.badge tone="neutral">{{ ucfirst($preview->status) }}</x-signal.ui.badge></div>
                        @if($preview->url)<a href="https://{{ $preview->url }}" target="_blank" rel="noopener noreferrer" class="ui-link mt-2 block truncate text-sm">{{ $preview->url }}</a>@endif
                        @if($previewCleanup)
                            <div class="mt-2 border-t border-line pt-2 text-xs text-muted">
                                <p>{{ __('Stack cleanup: :status', ['status' => ucfirst($previewCleanup->status)]) }}</p>
                                @if($previewCleanup->error)<p class="mt-1">{{ $previewCleanup->error }}</p>@endif
                                @can('retryCleanup', $preview)
                                    @if($previewCleanup->status === \App\Modules\Deployer\Models\PreviewStackCleanup::STATUS_FAILED)
                                        <form method="POST" action="{{ route('projects.previews.cleanup.retry', [$project, $preview]) }}" class="mt-2">@csrf<x-signal.ui.button type="submit" variant="ghost" class="px-0">{{ __('Retry stack cleanup') }}</x-signal.ui.button></form>
                                    @endif
                                @endcan
                            </div>
                        @endif
                        @if($canManage && $preview->status !== 'closed' && $sourceSecrets->isNotEmpty())
                            <form method="POST" action="{{ route('projects.previews.secrets.approve', [$project, $preview]) }}" class="mt-3 border-t border-line pt-3">
                                @csrf
                                <x-signal.ui.input type="hidden" name="revision" value="{{ $preview->revision }}" :restore="false" />
                                <p class="text-xs leading-5 text-muted">{{ __('Previews receive no source secrets by default. Approve only the runtime keys this exact revision may use; rotated values require approval again.') }}</p>
                                <fieldset class="mt-2 grid gap-2 sm:grid-cols-2">
                                    <legend class="sr-only">{{ __('Preview secret keys') }}</legend>
                                    @foreach($sourceSecrets as $secret)
                                        <label class="flex items-center gap-2 text-sm text-ink"><x-signal.ui.input class="ui-check" type="checkbox" name="secret_keys[]" value="{{ $secret->key }}" :restore="false" /><span>{{ $secret->key }} <span class="text-xs text-muted">(v{{ $secret->current_version }})</span></span></label>
                                    @endforeach
                                </fieldset>
                                <x-signal.ui.button type="submit" variant="secondary" class="mt-3">{{ __('Approve selected preview secrets') }}</x-signal.ui.button>
                            </form>
                        @endif
                    </x-signal.ui.panel>
                @empty
                    <p class="text-sm text-muted">{{ __('No pull-request previews yet.') }}</p>
                @endforelse
            </div>
        </x-signal.ui.panel>
        @if($canManage && $featureAccess['previews'])
            <x-scenes.projects.preview-settings-dialog :project="$project" :open="$previewSettingsDialogOpen" />
        @endif
    </div>
    @if($canManage)
        <x-signal.overlays.modal
            id="application-configuration-dialog"
            :title="__('Configuration as code')"
            :description="__('Author, review and apply portable application configuration in context.')"
            :open="$configurationDialogOpen"
            body-class="p-0"
            data-modal-content-loaded="false"
            data-modal-content-url="{{ $configurationDialogContentUrl }}"
        >
            <div data-modal-content>
                <p class="p-5 text-sm text-muted">{{ __('Loading configuration workflow…') }}</p>
            </div>
        </x-signal.overlays.modal>
    @endif
</x-layouts.app>
