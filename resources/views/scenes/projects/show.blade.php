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

    <x-layouts.partials.heading icon="view-grid" :title="$project->name" :description="$project->description ?: __('Application environments and resources.')">
        <x-slot:buttons>
            @if($canManage)
                <x-ui.button
                    :href="$configurationDialogUrl"
                    data-modal-trigger="application-configuration-dialog"
                    data-modal-content-url="{{ $configurationDialogContentUrl }}"
                    aria-controls="application-configuration-dialog"
                    aria-expanded="{{ $configurationDialogOpen ? 'true' : 'false' }}"
                    variant="secondary"
                >{{ __('Configuration as code') }}</x-ui.button>
                <form method="POST" action="{{ route('projects.destroy', $project) }}">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger">{{ __('Delete application') }}</x-ui.button>
                </form>
            @endif
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <x-ui.insights
        id="project-insights"
        class="mt-8"
        :summary="trans_choice(':count environment|:count environments', $project->environments->count(), ['count' => $project->environments->count()])"
        :mobile-open="true"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('Application summary') }}">
            <x-ui.stat
                :label="__('Environments')"
                :value="$project->environments->count()"
                :description="__('Targets managed by this application.')"
            />
            <x-ui.stat
                :label="__('Attached sites')"
                :value="$project->environments->whereNotNull('website_id')->count()"
                :description="__('Environments connected to a website.')"
            />
            <x-ui.stat
                :label="__('Runtime')"
                :value="$project->environments->contains(fn ($environment) => $environment->hibernated_at) ? __('Partially hibernated') : __('Running')"
                :description="__('Current environment runtime state.')"
            />
            <x-ui.stat
                :label="__('Previews')"
                :value="$project->preview_enabled ? __('Enabled') : __('Disabled')"
                :description="__('Pull-request preview environments.')"
            />
        </dl>
    </x-ui.insights>

    <div class="mt-5 space-y-4">
        @foreach($project->environments as $environment)
            @php
                $environmentRepositories = $environment->website?->repositories ?? collect();
                $repository = $environmentRepositories->firstWhere('branch', $environment->branch);
                $deploymentInProgress = $environmentRepositories->contains(fn ($source) => $source->latestBuild && in_array($source->latestBuild->status, \App\Models\Build::ACTIVE_STATUSES, true));
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
            <section class="ui-card overflow-hidden shadow-xs" aria-labelledby="environment-{{ $environment->id }}-heading">
                <div class="flex flex-wrap items-center gap-4 border-b border-primary px-5 py-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-secondary font-black text-ternary">{{ strtoupper(substr($environment->name, 0, 1)) }}</div>
                    <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><h2 id="environment-{{ $environment->id }}-heading" class="text-lg font-black text-primary">{{ $environment->name }}</h2>@if($environment->is_protected)<x-ui.badge tone="accent">{{ __('Protected') }}</x-ui.badge>@endif @if($environment->hibernated_at)<x-ui.badge tone="neutral">{{ __('Hibernated') }}</x-ui.badge>@endif</div><p class="mt-0.5 truncate font-mono text-xs text-secondary">{{ $environment->branch }} · {{ ucfirst($environment->type) }}</p></div>
                    <div class="flex w-full flex-wrap gap-2 text-xs sm:w-auto"><span class="rounded-lg bg-secondary px-3 py-2 text-secondary">{{ $environment->server?->label ?? __('No server') }}</span><span class="rounded-lg bg-secondary px-3 py-2 text-secondary">{{ $environment->website?->name ?? __('No site') }}</span><span class="rounded-lg bg-secondary px-3 py-2 font-bold text-secondary">{{ $environment->minimum_replicas }}–{{ $environment->maximum_replicas }}×</span><x-ui.button :href="route('observability.environments.context', $environment)" variant="secondary">{{ __('Investigate evidence') }}</x-ui.button></div>
                </div>

                @if(!$repository?->builds()->where('status', \App\Models\Build::STATUS_SUCCEEDED)->exists())
                    @php
                        $readiness = [
                        ['label' => __('Active server attached'), 'ready' => $environment->server?->provisioning_status === \App\Models\Server::STATUS_ACTIVE, 'url' => route('servers.index')],
                        ['label' => __('Active website attached'), 'ready' => $environment->website?->provisioning_status === \App\Models\Website::STATUS_ACTIVE, 'url' => route('websites.index')],
                        ['label' => __('Repository and branch connected'), 'ready' => (bool) $repository, 'url' => $environment->website ? $environmentRepositoryCreateUrl : $environmentWebsiteCreateUrl, 'modal' => $environment->website ? 'repository-create-dialog' : 'website-create-dialog', 'content_url' => $environment->website ? $environmentRepositoryCreateContentUrl : $environmentWebsiteCreateContentUrl],
                        ['label' => __('Provider credentials available'), 'ready' => (bool) $repository?->provider_id, 'url' => route('providers.index')],
                        ];
                        $readyCount = collect($readiness)->where('ready', true)->count();
                    @endphp
                    <aside class="ui-alert ui-alert--info rounded-none border-x-0 border-t-0 px-5 py-4" aria-label="{{ __('First deployment readiness') }}">
                        <div class="flex flex-wrap items-center justify-between gap-3"><div><p class="font-bold text-primary">{{ __('First deployment readiness') }}</p><p class="mt-1 text-xs text-secondary">{{ __(':ready of :total checks passed. Deployment stays disabled until every dependency is active.', ['ready' => $readyCount, 'total' => count($readiness)]) }}</p></div><a href="{{ route('docs') }}#first-deploy" class="text-xs font-bold text-ternary underline">{{ __('Open setup guide') }}</a></div>
                        <ul class="mt-3 grid gap-2 sm:grid-cols-2">@foreach($readiness as $check)<li class="flex items-center gap-2 text-xs text-secondary"><span aria-hidden="true" class="font-black text-ternary">{{ $check['ready'] ? '✓' : '○' }}</span>@if($check['ready'])<span>{{ $check['label'] }}</span>@else<a href="{{ $check['url'] }}" @if(isset($check['modal'])) data-modal-trigger="{{ $check['modal'] }}" data-modal-content-url="{{ $check['content_url'] }}" aria-controls="{{ $check['modal'] }}" aria-expanded="{{ ($check['modal'] === 'repository-create-dialog' ? $repositoryCreateOpen : $websiteCreateOpen) ? 'true' : 'false' }}" @endif class="font-bold text-ternary underline">{{ $check['label'] }}</a>@endif</li>@endforeach</ul>
                    </aside>
                @endif

                <div class="flex flex-wrap items-center gap-3 border-b border-primary bg-secondary px-5 py-3">
                    <div class="min-w-0 flex-1">
                        @if($repository)
                            <p class="truncate text-sm font-bold text-primary">{{ $repository->name }} <span class="font-mono font-normal text-secondary">· {{ $repository->branch }}</span></p>
                            <p class="mt-0.5 text-xs text-secondary">@if($repository->latestBuild){{ __('Latest deployment: :status', ['status' => str($repository->latestBuild->status)->replace('_', ' ')]) }}@else{{ __('Ready for the first deployment') }}@endif</p>
                        @elseif($environment->website)
                            <p class="text-sm font-bold text-primary">{{ __('Connect source control') }}</p><p class="mt-0.5 text-xs text-secondary">{{ __('Attach a repository to complete this environment.') }}</p>
                        @else
                            <p class="text-sm font-bold text-primary">{{ __('Attach infrastructure') }}</p><p class="mt-0.5 text-xs text-secondary">{{ __('Select a ready server and website before connecting source control.') }}</p>
                        @endif
                    </div>
                    @if($repository)
                        <x-ui.button :href="route('repositories.show', $repository)" variant="secondary">{{ __('View source') }}</x-ui.button>
                        @if($canDeploy)<form method="POST" action="{{ route('repositories.deploy', $repository) }}">@csrf<x-ui.button type="submit" variant="primary" :disabled="! $deploymentReady || $deploymentInProgress">{{ $deploymentInProgress ? __('Deploying…') : ($deploymentReady ? __('Deploy now') : __('Not ready')) }}</x-ui.button></form>@endif
                    @elseif($environment->website && $canDeploy)
                        <x-ui.button :href="$environmentRepositoryCreateUrl" data-modal-trigger="repository-create-dialog" data-modal-content-url="{{ $environmentRepositoryCreateContentUrl }}" aria-controls="repository-create-dialog" aria-expanded="{{ $repositoryCreateOpen ? 'true' : 'false' }}" variant="primary">{{ __('Connect repository') }}</x-ui.button>
                    @elseif($canDeploy)
                        <x-ui.button :href="$environmentWebsiteCreateUrl" data-modal-trigger="website-create-dialog" data-modal-content-url="{{ $environmentWebsiteCreateContentUrl }}" aria-controls="website-create-dialog" aria-expanded="{{ $websiteCreateOpen ? 'true' : 'false' }}" variant="primary">{{ __('Create website') }}</x-ui.button>
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
                    <aside class="ui-alert ui-alert--info rounded-none border-x-0 border-t-0 px-5 py-4">
                        <div class="flex flex-wrap items-center gap-3"><div class="min-w-0 flex-1"><p class="font-bold text-primary">{{ __('Promote tested release') }}</p><p class="mt-1 text-xs text-secondary">{{ __('Rebuild exact revision :revision with the target environment configuration. Target approval and maintenance policies still apply.', ['revision'=>$successfulBuild->shortRevision()]) }}</p></div><x-ui.button href="{{ $promotionDialogUrl }}" data-modal-trigger="{{ $promotionDialogId }}" aria-controls="{{ $promotionDialogId }}" aria-expanded="{{ $promotionDialogOpen ? 'true' : 'false' }}" variant="primary">{{ __('Promote') }}</x-ui.button></div>
                    </aside>
                    <x-scenes.projects.promotion-dialog
                        :build="$successfulBuild"
                        :open="$promotionDialogOpen"
                        :targets="$promotionTargets"
                    />
                @endif

                <div class="grid gap-px bg-secondary lg:grid-cols-3">
                    <section id="environment-{{ $environment->id }}-settings" class="bg-primary p-5" aria-labelledby="environment-{{ $environment->id }}-settings-heading">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 id="environment-{{ $environment->id }}-settings-heading" class="font-bold text-primary">{{ __('Environment settings') }}</h3>
                                <p class="mt-1 text-xs text-secondary">{{ ucfirst($environment->runtime_type ?: 'php') }} · {{ $environment->branch }} · {{ $environment->website?->name ?? __('No website') }}</p>
                            </div>
                            @can('update', $environment)
                                <x-ui.button :href="$environmentSettingsDialogUrl" data-modal-trigger="{{ $environmentSettingsDialogId }}" aria-controls="{{ $environmentSettingsDialogId }}" aria-expanded="{{ $environmentSettingsDialogOpen ? 'true' : 'false' }}" variant="secondary">{{ __('Edit settings') }}</x-ui.button>
                            @endcan
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2 text-xs">
                            <x-ui.badge :tone="$environment->is_protected ? 'accent' : 'neutral'">{{ $environment->is_protected ? __('Protected') : __('Unprotected') }}</x-ui.badge>
                            <x-ui.badge :tone="$environment->requires_deployment_approval ? 'accent' : 'neutral'">{{ $environment->requires_deployment_approval ? __('Approval required') : __('Auto deploy') }}</x-ui.badge>
                            <span class="rounded-lg bg-secondary px-3 py-2 text-secondary">{{ $environment->server?->label ?? __('No server') }}</span>
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

                    <section id="environment-{{ $environment->id }}-deployment-controls" class="bg-primary p-5" aria-labelledby="environment-{{ $environment->id }}-deployment-controls-heading">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 id="environment-{{ $environment->id }}-deployment-controls-heading" class="font-bold text-primary">{{ __('Deployment controls') }}</h3>
                                <div class="mt-2 flex flex-wrap gap-2 text-xs"><x-ui.badge :tone="$environment->deployment_locked_at ? 'danger' : 'success'">{{ $environment->deployment_locked_at ? __('Locked') : __('Unlocked') }}</x-ui.badge>@if($environment->deployment_window_days)<x-ui.badge tone="neutral">{{ __('Maintenance window active') }}</x-ui.badge>@endif<x-ui.badge tone="neutral">{{ str($environment->deployment_strategy ?: 'blue_green')->replace('_', ' ')->title() }}</x-ui.badge></div>
                            </div>
                            @can('update', $environment)
                                <x-ui.button :href="$deploymentControlsDialogUrl" data-modal-trigger="{{ $deploymentControlsDialogId }}" aria-controls="{{ $deploymentControlsDialogId }}" aria-expanded="{{ $deploymentControlsDialogOpen ? 'true' : 'false' }}" variant="secondary">{{ __('Edit controls') }}</x-ui.button>
                            @endcan
                        </div>
                    </section>
                    @can('update', $environment)
                        <x-scenes.projects.deployment-controls-dialog
                            :environment="$environment"
                            :open="$deploymentControlsDialogOpen"
                        />
                    @endcan

                    <details id="environment-{{ $environment->id }}-runtime-capacity" class="group bg-primary p-5">
                        <summary class="flex cursor-pointer list-none items-center justify-between font-bold text-primary"><span>{{ __('Runtime capacity') }}</span><span class="text-secondary group-open:rotate-45">+</span></summary>
                        <p class="mt-3 text-sm text-secondary">{{ __('Pre-provision worker capacity and pause idle environments. Runtime changes are applied from Automation.') }}</p>
                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Minimum') }}</span><input value="{{ $environment->minimum_replicas }}" class="input secondary rounded-lg" disabled></label>
                            <label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Maximum') }}</span><input value="{{ $environment->maximum_replicas }}" class="input secondary rounded-lg" disabled></label>
                            <label class="col-span-2"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Idle hibernation') }}</span><input value="{{ $environment->hibernate_after_minutes ? __('After :minutes minutes', ['minutes' => $environment->hibernate_after_minutes]) : __('Never') }}" class="input secondary rounded-lg" disabled></label>
                        </div>
                        @if($featureAccess['scaling'] || $featureAccess['hibernation'])<x-ui.button :href="route('automation.index')" variant="secondary" class="mt-4">{{ __('Open automation') }}</x-ui.button>@else<p class="mt-4 text-sm text-secondary"><a href="{{ route('pricing') }}" class="font-bold text-ternary">{{ __('View plans') }}</a> {{ __('to unlock runtime controls.') }}</p>@endif
                    </details>

                    <section id="environment-{{ $environment->id }}-variables" class="bg-primary p-5" aria-labelledby="environment-{{ $environment->id }}-variables-heading">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 id="environment-{{ $environment->id }}-variables-heading" class="font-bold text-primary">{{ __('Encrypted variables') }} <span class="text-secondary">({{ $environment->variables->count() }})</span></h3>
                                <p class="mt-1 text-xs text-secondary">{{ __('Versioned values are encrypted and never shown on this page.') }}</p>
                            </div>
                            @can('update', $environment)
                                <x-ui.button :href="$variableDialogUrl" data-modal-trigger="{{ $variableDialogId }}" aria-controls="{{ $variableDialogId }}" aria-expanded="{{ $variableDialogOpen ? 'true' : 'false' }}" variant="secondary">{{ __('Add variable') }}</x-ui.button>
                            @endcan
                        </div>
                        <div class="mt-4 space-y-2">@forelse($environment->variables as $variable)<div class="flex items-center gap-2 rounded-lg border border-primary bg-secondary px-3 py-2"><div class="min-w-0 flex-1"><code class="block truncate text-xs text-primary">{{ $variable->key }}</code><span class="text-[11px] text-secondary">{{ str($variable->scope)->replace('_', ' ')->headline() }} · v{{ $variable->current_version }}@if($variable->rotation_due_at) · <span class="{{ $variable->rotation_due_at->isPast() ? 'text-red-600' : '' }}">{{ __('rotate :date', ['date' => $variable->rotation_due_at->toDateString()]) }}</span>@endif</span></div><span class="text-xs text-secondary">{{ $variable->is_secret ? '••••••••' : __('Encrypted') }}</span>@can('update', $environment)<form method="POST" action="{{ route('environments.variables.destroy', [$environment, $variable]) }}">@csrf @method('DELETE')<button type="submit" class="text-xs font-bold text-secondary">{{ __('Delete') }}</button></form>@endcan</div>@empty<p class="text-sm text-secondary">{{ __('No variables yet.') }}</p>@endforelse</div>
                        @can('update', $environment)
                            <x-scenes.projects.variable-create-dialog :environment="$environment" :open="$variableDialogOpen" />
                        @endcan
                    </section>
                </div>

                <div class="grid gap-5 border-t border-primary p-5 lg:grid-cols-2">
                    <section id="environment-{{ $environment->id }}-processes" class="ui-card ui-card--muted p-4" aria-labelledby="environment-{{ $environment->id }}-processes-heading">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 id="environment-{{ $environment->id }}-processes-heading" class="font-bold text-primary">{{ __('Workers and scheduler') }} <span class="text-secondary">({{ $environment->processes->count() }})</span></h3>
                                <p class="mt-1 text-xs text-secondary">{{ __('Encrypted commands are applied on the next deployment.') }}</p>
                            </div>
                            @can('update', $environment)
                                @if($featureAccess['workers'])
                                    <x-ui.button :href="$processDialogUrl" data-modal-trigger="{{ $processDialogId }}" aria-controls="{{ $processDialogId }}" aria-expanded="{{ $processDialogOpen ? 'true' : 'false' }}" variant="secondary">{{ __('Add process') }}</x-ui.button>
                                @endif
                            @endcan
                        </div>
                        <div class="mt-3 space-y-2">@foreach($environment->processes as $process)<div class="flex items-center gap-3 rounded-lg border border-primary bg-primary p-3"><div class="min-w-0 flex-1"><p class="font-bold text-primary">{{ $process->name }} <span class="font-normal text-secondary">· {{ $process->type }} · ×{{ $process->replicas }}</span></p><p class="mt-1 text-xs text-secondary">{{ __('Command encrypted · applied on next deployment') }}</p></div>@can('update', $environment)<form method="POST" action="{{ route('environments.processes.destroy', [$environment, $process]) }}">@csrf @method('DELETE')<button type="submit" class="text-xs font-bold text-secondary">{{ __('Delete') }}</button></form>@endcan</div>@endforeach</div>
                        @can('update', $environment)
                            @if($featureAccess['workers'])
                                <x-scenes.projects.process-create-dialog :environment="$environment" :open="$processDialogOpen" />
                            @else
                                <p class="mt-4 text-sm text-secondary">{{ __('Available on Starter and higher.') }} <a href="{{ route('pricing') }}" class="font-bold text-ternary">{{ __('View plans') }}</a></p>
                            @endif
                        @endcan
                    </section>

                    <details id="environment-{{ $environment->id }}-resources" class="ui-card ui-card--muted group p-4" @if($resourcesOpen) open @endif>
                        <summary class="flex cursor-pointer list-none items-center justify-between font-bold text-primary"><span>{{ __('Attached resources') }} <span class="text-secondary">({{ $environment->resources->count() }})</span></span><span class="text-secondary group-open:rotate-45">+</span></summary>
                        <div class="mt-3 space-y-2">@foreach($environment->resources as $resource)<div class="flex items-center gap-3 rounded-lg border border-primary bg-primary p-3"><div class="min-w-0 flex-1"><p class="font-bold text-primary">{{ $resource->name }}</p><p class="text-xs text-secondary">{{ str($resource->type)->replace('_', ' ')->title() }} · {{ $resource->is_managed ? __('Managed') : __('External') }} · {{ ucfirst($resource->status) }}</p></div>@can('update', $environment)<form method="POST" action="{{ route('environments.resources.destroy', [$environment, $resource]) }}">@csrf @method('DELETE')<button type="submit" class="text-xs font-bold text-secondary">{{ __('Detach') }}</button></form>@endcan</div>@endforeach</div>
                        @can('update', $environment)
                            @if($featureAccess['resources'])
                                <x-ui.button :href="$resourceDialogUrl" data-modal-trigger="{{ $resourceDialogId }}" aria-controls="{{ $resourceDialogId }}" aria-expanded="{{ $resourceDialogOpen ? 'true' : 'false' }}" variant="secondary" class="mt-4">{{ __('Attach resource') }}</x-ui.button>
                            @else
                                <p class="mt-4 text-sm text-secondary">{{ __('Available on Pro and higher.') }} <a href="{{ route('pricing') }}" class="font-bold text-ternary">{{ __('View plans') }}</a></p>
                            @endif
                        @endcan
                    </details>
                    @can('update', $environment)
                        @if($featureAccess['resources'])
                            <x-scenes.projects.resource-create-dialog :environment="$environment" :open="$resourceDialogOpen" />
                        @endif
                    @endcan
                </div>
            </section>
        @endforeach
    </div>

    @php($addEnvironmentOpen = old('_environment_form') === 'add' || request()->query('dialog') === 'add-environment')
    @php($addEnvironmentDialogId = 'add-environment-dialog')
    @php($addEnvironmentDialogUrl = route('projects.show', ['project' => $project, 'dialog' => 'add-environment']))

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        @if($canDeploy)
            <section id="add-environment" class="ui-card p-5" aria-labelledby="add-environment-heading">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><h2 id="add-environment-heading" class="font-black text-primary">{{ __('Add environment') }}</h2><p class="mt-2 text-sm text-secondary">{{ __('Create a separate runtime for a branch, then attach infrastructure and source control.') }}</p></div>
                    <x-ui.button :href="$addEnvironmentDialogUrl" data-modal-trigger="{{ $addEnvironmentDialogId }}" aria-controls="{{ $addEnvironmentDialogId }}" aria-expanded="{{ $addEnvironmentOpen ? 'true' : 'false' }}" variant="primary">{{ __('Add environment') }}</x-ui.button>
                </div>
                <x-scenes.projects.environment-create-dialog :project="$project" :open="$addEnvironmentOpen" />
            </section>
        @endif

        @php($previewPanelOpen = $project->previews->isNotEmpty() || old('_project_form') === 'previews')

        <details id="preview-environments" class="ui-card p-5" @if($previewPanelOpen) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 font-black text-primary"><span>{{ __('Preview environments') }} <span class="text-secondary">({{ $project->previews->count() }})</span></span><x-ui.badge :tone="$project->preview_enabled ? 'success' : 'neutral'">{{ $project->preview_enabled ? __('Enabled') : __('Disabled') }}</x-ui.badge></summary>
            @if($canManage && $featureAccess['previews'])
                <x-ui.button :href="$previewSettingsDialogUrl" data-modal-trigger="{{ $previewSettingsDialogId }}" aria-controls="{{ $previewSettingsDialogId }}" aria-expanded="{{ $previewSettingsDialogOpen ? 'true' : 'false' }}" variant="secondary" class="mt-4">{{ __('Configure previews') }}</x-ui.button>
            @elseif($canManage)
                <p class="mt-4 text-sm text-secondary">{{ __('Available on Pro and higher.') }} <a href="{{ route('pricing') }}" class="font-bold text-ternary">{{ __('View plans') }}</a></p>
            @endif
            <div class="mt-4 space-y-2">
                @forelse($project->previews->sortByDesc('last_activity_at') as $preview)
                    @php($sourceSecrets = $preview->sourceEnvironment?->variables->where('is_secret', true)->whereIn('scope', ['runtime', 'all'])->whereNotIn('key', \App\Models\PreviewSecretApproval::PROTECTED_KEYS) ?? collect())
                    @php($previewCleanup = $preview->stackCleanups->sortByDesc('id')->first())
                    <article class="ui-card ui-card--muted p-3">
                        <div class="flex items-center gap-3"><div class="min-w-0 flex-1"><p class="font-bold text-primary">#{{ $preview->pull_request_number }} · {{ $preview->title ?: $preview->source_branch }}</p><p class="truncate font-mono text-xs text-secondary">{{ $preview->source_branch }} · {{ substr($preview->revision, 0, 12) }}</p></div><x-ui.badge tone="neutral">{{ ucfirst($preview->status) }}</x-ui.badge></div>
                        @if($preview->url)<a href="https://{{ $preview->url }}" target="_blank" rel="noopener noreferrer" class="mt-2 block truncate text-sm font-medium text-ternary">{{ $preview->url }}</a>@endif
                        @if($previewCleanup)
                            <div class="mt-2 border-t border-primary pt-2 text-xs text-secondary">
                                <p>{{ __('Stack cleanup: :status', ['status' => ucfirst($previewCleanup->status)]) }}</p>
                                @if($previewCleanup->error)<p class="mt-1">{{ $previewCleanup->error }}</p>@endif
                                @can('retryCleanup', $preview)
                                    @if($previewCleanup->status === \App\Models\PreviewStackCleanup::STATUS_FAILED)
                                        <form method="POST" action="{{ route('projects.previews.cleanup.retry', [$project, $preview]) }}" class="mt-2">@csrf<x-ui.button type="submit" variant="ghost" class="px-0">{{ __('Retry stack cleanup') }}</x-ui.button></form>
                                    @endif
                                @endcan
                            </div>
                        @endif
                        @if($canManage && $preview->status !== 'closed' && $sourceSecrets->isNotEmpty())
                            <form method="POST" action="{{ route('projects.previews.secrets.approve', [$project, $preview]) }}" class="mt-3 border-t border-primary pt-3">
                                @csrf
                                <input type="hidden" name="revision" value="{{ $preview->revision }}">
                                <p class="text-xs leading-5 text-secondary">{{ __('Previews receive no source secrets by default. Approve only the runtime keys this exact revision may use; rotated values require approval again.') }}</p>
                                <fieldset class="mt-2 grid gap-2 sm:grid-cols-2">
                                    <legend class="sr-only">{{ __('Preview secret keys') }}</legend>
                                    @foreach($sourceSecrets as $secret)
                                        <label class="flex items-center gap-2 text-sm text-primary"><input type="checkbox" name="secret_keys[]" value="{{ $secret->key }}"><span>{{ $secret->key }} <span class="text-xs text-secondary">(v{{ $secret->current_version }})</span></span></label>
                                    @endforeach
                                </fieldset>
                                <x-ui.button type="submit" variant="secondary" class="mt-3">{{ __('Approve selected preview secrets') }}</x-ui.button>
                            </form>
                        @endif
                    </article>
                @empty
                    <p class="text-sm text-secondary">{{ __('No pull-request previews yet.') }}</p>
                @endforelse
            </div>
        </details>
        @if($canManage && $featureAccess['previews'])
            <x-scenes.projects.preview-settings-dialog :project="$project" :open="$previewSettingsDialogOpen" />
        @endif
    </div>
    @if($canManage)
        <x-dialogs.modal
            id="application-configuration-dialog"
            :title="__('Configuration as code')"
            :description="__('Author, review and apply portable application configuration in context.')"
            :open="$configurationDialogOpen"
            body-class="p-0"
            data-modal-content-loaded="false"
            data-modal-content-url="{{ $configurationDialogContentUrl }}"
        >
            <div data-modal-content>
                <p class="p-5 text-sm text-secondary">{{ __('Loading configuration workflow…') }}</p>
            </div>
        </x-dialogs.modal>
    @endif
</x-layouts.app>
