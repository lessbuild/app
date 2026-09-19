<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('projects.index')" :title="__('Back to applications')" />

    <x-layouts.partials.heading icon="view-grid" :title="$project->name" :description="$project->description ?: __('Application environments and resources.')">
        <x-slot:buttons>
            @if($canManage)
                <x-ui.button :href="route('projects.configuration.create', $project)" variant="secondary">{{ __('Configuration as code') }}</x-ui.button>
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
                $variableDialogId = 'environment-variable-dialog-'.$environment->id;
                $variableDialogKey = 'add-variable-'.$environment->id;
                $variableDialogOpen = $canUpdateEnvironment && ($variablesOpen || request()->query('dialog') === $variableDialogKey);
                $variableDialogUrl = route('projects.show', ['project' => $project, 'dialog' => $variableDialogKey]);
                $processDialogId = 'environment-process-dialog-'.$environment->id;
                $processDialogKey = 'add-process-'.$environment->id;
                $processDialogOpen = $canUpdateEnvironment && $featureAccess['workers'] && ($processesOpen || request()->query('dialog') === $processDialogKey);
                $processDialogUrl = route('projects.show', ['project' => $project, 'dialog' => $processDialogKey]);
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
                        ['label' => __('Repository and branch connected'), 'ready' => (bool) $repository, 'url' => $environment->website ? route('repositories.index', ['dialog' => 'create-repository', 'website_id' => $environment->website_id, 'branch' => $environment->branch]) : route('websites.index', ['dialog' => 'create-website'])],
                        ['label' => __('Provider credentials available'), 'ready' => (bool) $repository?->provider_id, 'url' => route('providers.index')],
                        ];
                        $readyCount = collect($readiness)->where('ready', true)->count();
                    @endphp
                    <aside class="ui-alert ui-alert--info rounded-none border-x-0 border-t-0 px-5 py-4" aria-label="{{ __('First deployment readiness') }}">
                        <div class="flex flex-wrap items-center justify-between gap-3"><div><p class="font-bold text-primary">{{ __('First deployment readiness') }}</p><p class="mt-1 text-xs text-secondary">{{ __(':ready of :total checks passed. Deployment stays disabled until every dependency is active.', ['ready' => $readyCount, 'total' => count($readiness)]) }}</p></div><a href="{{ route('docs') }}#first-deploy" class="text-xs font-bold text-ternary underline">{{ __('Open setup guide') }}</a></div>
                        <ul class="mt-3 grid gap-2 sm:grid-cols-2">@foreach($readiness as $check)<li class="flex items-center gap-2 text-xs text-secondary"><span aria-hidden="true" class="font-black text-ternary">{{ $check['ready'] ? '✓' : '○' }}</span>@if($check['ready'])<span>{{ $check['label'] }}</span>@else<a href="{{ $check['url'] }}" class="font-bold text-ternary underline">{{ $check['label'] }}</a>@endif</li>@endforeach</ul>
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
                        <x-ui.button :href="route('repositories.index', ['dialog' => 'create-repository', 'website_id' => $environment->website_id, 'branch' => $environment->branch])" variant="primary">{{ __('Connect repository') }}</x-ui.button>
                    @elseif($canDeploy)
                        <x-ui.button :href="route('websites.index', ['dialog' => 'create-website'])" variant="primary">{{ __('Create website') }}</x-ui.button>
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
                    <details id="environment-{{ $environment->id }}-settings" class="group bg-primary p-5" @if($environmentSettingsOpen) open @endif>
                        <summary class="flex cursor-pointer list-none items-center justify-between font-bold text-primary"><span>{{ __('Environment settings') }}</span><span class="text-secondary group-open:rotate-45">+</span></summary>
                        <form method="POST" action="{{ route('environments.update', $environment) }}" class="mt-4 grid gap-3 sm:grid-cols-2">@csrf<input type="hidden" name="_environment_id" value="{{ $environment->id }}"><input type="hidden" name="_environment_panel" value="settings"> @method('PATCH')
                            <label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Name') }}</span><input name="name" value="{{ $environment->name }}" class="input secondary rounded-lg" required></label>
                            <label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Type') }}</span><select name="type" class="input secondary rounded-lg">@foreach(\App\Models\Environment::TYPES as $type)<option value="{{ $type }}" @selected($environment->type === $type)>{{ ucfirst($type) }}</option>@endforeach</select></label>
                            <label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Branch') }}</span><input name="branch" value="{{ $environment->branch }}" class="input secondary rounded-lg" required></label>
                            <label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Runtime') }}</span><select name="runtime_type" class="input secondary rounded-lg">@foreach(\App\Models\Environment::RUNTIME_TYPES as $runtime)<option value="{{ $runtime }}" @selected(($environment->runtime_type ?: 'php') === $runtime)>{{ ucfirst($runtime) }}</option>@endforeach</select></label>
                            <label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Runtime version') }}</span><input name="runtime_version" value="{{ $environment->runtime_version }}" placeholder="20" class="input secondary rounded-lg"></label>
                            <label class="sm:col-span-2"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Build command') }}</span><input name="build_command" value="{{ $environment->build_command }}" placeholder="npm run build" class="input secondary rounded-lg font-mono"></label>
                            <label class="sm:col-span-2"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Start command') }}</span><input name="start_command" value="{{ $environment->start_command }}" placeholder="npm start" class="input secondary rounded-lg font-mono"></label>
                            <label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Application port') }}</span><input type="number" min="1" max="65535" name="container_port" value="{{ $environment->container_port }}" placeholder="3000" class="input secondary rounded-lg"></label>
                            <label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Dockerfile path') }}</span><input name="dockerfile_path" value="{{ $environment->dockerfile_path }}" placeholder="Dockerfile" class="input secondary rounded-lg font-mono"></label>
                            <label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Server') }}</span><select name="server_id" class="input secondary rounded-lg"><option value="">{{ __('None') }}</option>@foreach($servers as $server)<option value="{{ $server->id }}" @selected($environment->server_id === $server->id)>{{ $server->label }}</option>@endforeach</select></label>
                            <label class="sm:col-span-2"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Website') }}</span><select name="website_id" class="input secondary rounded-lg"><option value="">{{ __('None') }}</option>@foreach($websites as $website)<option value="{{ $website->id }}" @selected($environment->website_id === $website->id)>{{ $website->name }}</option>@endforeach</select></label>
                            @if($featureAccess['hibernation'])<label class="sm:col-span-2"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Hibernate after inactivity') }}</span><select name="hibernate_after_minutes" class="input secondary rounded-lg"><option value="">{{ __('Never') }}</option>@foreach([5, 15, 30, 60, 120, 1440] as $minutes)<option value="{{ $minutes }}" @selected($environment->hibernate_after_minutes === $minutes)>{{ trans_choice(':count minute|:count minutes', $minutes, ['count' => $minutes]) }}</option>@endforeach</select></label>@endif
                            @if($featureAccess['monitoring'])<label class="sm:col-span-2"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Observe after deployment') }}</span><select name="post_deployment_observation_minutes" class="input secondary rounded-lg"><option value="">{{ __('Disabled') }}</option>@foreach(\App\Models\Environment::POST_DEPLOYMENT_OBSERVATION_MINUTES as $minutes)<option value="{{ $minutes }}" @selected($environment->post_deployment_observation_minutes === $minutes)>{{ __('For :minutes minutes', ['minutes' => $minutes]) }}</option>@endforeach</select><span class="mt-1 block text-xs text-secondary">{{ __('Check the deployed website for this window and keep the revision-linked result available for troubleshooting.') }}</span></label>@endif
                            <input type="hidden" name="is_protected" value="0"><label class="flex items-center gap-2"><input type="checkbox" name="is_protected" value="1" @checked($environment->is_protected)><span class="text-sm text-secondary">{{ __('Protect') }}</span></label>
                            <input type="hidden" name="requires_deployment_approval" value="0"><label class="flex items-center gap-2"><input type="checkbox" name="requires_deployment_approval" value="1" @checked($environment->requires_deployment_approval)><span class="text-sm text-secondary">{{ __('Require approval') }}</span></label>
                            @can('update', $environment)<x-ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Save settings') }}</x-ui.button>@endcan
                        </form>
                    </details>

                    <details id="environment-{{ $environment->id }}-deployment-controls" class="group bg-primary p-5" @if($deploymentControlsOpen) open @endif>
                        <summary class="flex cursor-pointer list-none items-center justify-between font-bold text-primary"><span>{{ __('Deployment controls') }}</span><span class="text-secondary group-open:rotate-45">+</span></summary>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs"><x-ui.badge :tone="$environment->deployment_locked_at ? 'danger' : 'success'">{{ $environment->deployment_locked_at ? __('Locked') : __('Unlocked') }}</x-ui.badge>@if($environment->deployment_window_days)<x-ui.badge tone="neutral">{{ __('Maintenance window active') }}</x-ui.badge>@endif</div>
                        @can('update', $environment)
                            <form method="POST" action="{{ route('environments.deployment-controls.update', $environment) }}" class="mt-4 space-y-4">@csrf<input type="hidden" name="_environment_id" value="{{ $environment->id }}"><input type="hidden" name="_environment_panel" value="deployment-controls"> @method('PATCH')
                                <label class="flex items-start gap-3"><input type="hidden" name="deployment_locked" value="0"><input type="checkbox" name="deployment_locked" value="1" class="mt-1" @checked($environment->deployment_locked_at)><span><span class="block text-sm font-bold text-primary">{{ __('Lock deployments') }}</span><span class="text-xs text-secondary">{{ __('Manual, API, scheduled, and webhook deployments will wait.') }}</span></span></label>
                                <input name="deployment_lock_reason" maxlength="500" value="{{ old('deployment_lock_reason', $environment->deployment_lock_reason) }}" class="input secondary rounded-lg" placeholder="{{ __('Reason for the lock (optional)') }}">
                                <div class="border-t border-primary pt-4"><label class="flex items-start gap-3"><input type="hidden" name="deployment_window_enabled" value="0"><input type="checkbox" name="deployment_window_enabled" value="1" class="mt-1" @checked($environment->deployment_window_days)><span><span class="block text-sm font-bold text-primary">{{ __('Restrict deployment times') }}</span><span class="text-xs text-secondary">{{ __('Allow new deployments only within this weekly window.') }}</span></span></label></div>
                                <fieldset><legend class="text-xs font-bold uppercase text-secondary">{{ __('Window days') }}</legend><div class="mt-2 flex flex-wrap gap-3">@foreach([1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')] as $day => $label)<label class="flex items-center gap-1.5 text-sm text-primary"><input type="checkbox" name="deployment_window_days[]" value="{{ $day }}" @checked(in_array($day, old('deployment_window_days', $environment->deployment_window_days ?? [])))>{{ $label }}</label>@endforeach</div></fieldset>
                                <div class="grid gap-3 sm:grid-cols-2"><label><span class="block text-xs font-bold uppercase text-secondary">{{ __('Starts') }}</span><input type="time" name="deployment_window_start" value="{{ old('deployment_window_start', $environment->deployment_window_start ? substr($environment->deployment_window_start, 0, 5) : '09:00') }}" class="input secondary mt-1 rounded-lg"></label><label><span class="block text-xs font-bold uppercase text-secondary">{{ __('Ends') }}</span><input type="time" name="deployment_window_end" value="{{ old('deployment_window_end', $environment->deployment_window_end ? substr($environment->deployment_window_end, 0, 5) : '17:00') }}" class="input secondary mt-1 rounded-lg"></label></div>
                                <label><span class="block text-xs font-bold uppercase text-secondary">{{ __('Timezone') }}</span><input name="deployment_window_timezone" list="deployment-timezones-{{ $environment->id }}" value="{{ old('deployment_window_timezone', $environment->deployment_window_timezone ?: 'UTC') }}" class="input secondary mt-1 rounded-lg" placeholder="Europe/London"><datalist id="deployment-timezones-{{ $environment->id }}"><option value="UTC"><option value="Europe/London"><option value="Europe/Berlin"><option value="America/New_York"><option value="America/Chicago"><option value="America/Denver"><option value="America/Los_Angeles"><option value="Asia/Singapore"><option value="Asia/Tokyo"><option value="Australia/Sydney"></datalist></label>
                                <div class="border-t border-primary pt-4"><label><span class="block text-xs font-bold uppercase text-secondary">{{ __('Release strategy') }}</span><select name="deployment_strategy" class="input secondary mt-1 w-full rounded-lg"><option value="blue_green" @selected($environment->deployment_strategy === 'blue_green')>{{ __('Blue/green atomic switch') }}</option><option value="canary" @selected($environment->deployment_strategy === 'canary')>{{ __('Canary candidate validation') }}</option><option value="rolling" @selected($environment->deployment_strategy === 'rolling')>{{ __('Rolling worker restart') }}</option></select></label><p class="mt-2 text-xs leading-5 text-secondary">{{ __('Blue/green atomically switches release directories. Canary sends loopback HTTP requests to the candidate before the switch. Rolling keeps replicated workers available while restarting them one at a time; web traffic still switches atomically.') }}</p></div>
                                <label><span class="block text-xs font-bold uppercase text-secondary">{{ __('Pause between rolling workers') }}</span><select name="rolling_pause_seconds" class="input secondary mt-1 w-full rounded-lg">@foreach([0,1,2,5,10,30] as $seconds)<option value="{{ $seconds }}" @selected((int) $environment->rolling_pause_seconds === $seconds)>{{ trans_choice(':count second|:count seconds', $seconds, ['count' => $seconds]) }}</option>@endforeach</select></label>
                                <label class="flex items-start gap-3 rounded-xl border border-primary bg-secondary p-3"><input type="hidden" name="automatic_rollback" value="0"><input type="checkbox" name="automatic_rollback" value="1" class="mt-1" @checked($environment->automatic_rollback)><span><span class="block text-sm font-bold text-primary">{{ __('Automatic rollback') }}</span><span class="text-xs leading-5 text-secondary">{{ __('If an activated release fails, immediately switch back to the most recent retained successful release.') }}</span></span></label>
                                <x-forms.errors name="deployment_window_days" /><x-forms.errors name="deployment_window_start" /><x-forms.errors name="deployment_window_end" /><x-forms.errors name="deployment_window_timezone" />
                                <x-ui.button type="submit" variant="primary">{{ __('Save deployment controls') }}</x-ui.button>
                            </form>
                        @endcan
                    </details>

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
                        @can('update', $environment)@if($featureAccess['resources'])<form method="POST" action="{{ route('environments.resources.store', $environment) }}" class="mt-4 grid gap-3 sm:grid-cols-2">@csrf<input type="hidden" name="_environment_id" value="{{ $environment->id }}"><input type="hidden" name="_environment_panel" value="resources"><input name="name" placeholder="primary-database" class="input secondary rounded-lg" required><select name="type" class="input secondary rounded-lg"><option value="mysql">MySQL</option><option value="postgresql">PostgreSQL</option><option value="redis">Redis</option><option value="valkey">Valkey</option><option value="object_storage">{{ __('Object storage') }}</option></select><label class="flex items-center gap-2 sm:col-span-2"><input type="hidden" name="is_managed" value="0"><input type="checkbox" name="is_managed" value="1"><span class="text-sm text-secondary">{{ __('Manage on attached server') }}</span></label><textarea name="variables" rows="3" placeholder="REDIS_HOST=cache.example.com&#10;REDIS_PASSWORD=…" class="input secondary rounded-lg font-mono sm:col-span-2"></textarea><x-ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Attach resource') }}</x-ui.button></form>@else<p class="mt-4 text-sm text-secondary">{{ __('Available on Pro and higher.') }} <a href="{{ route('pricing') }}" class="font-bold text-ternary">{{ __('View plans') }}</a></p>@endif @endcan
                    </details>
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
                <form method="POST" action="{{ route('projects.previews.update', $project) }}" class="mt-4 grid gap-3 sm:grid-cols-2">@csrf<input type="hidden" name="_project_form" value="previews"> @method('PATCH')<label class="flex items-center gap-2"><input type="hidden" name="preview_enabled" value="0"><input type="checkbox" name="preview_enabled" value="1" @checked($project->preview_enabled)><span class="text-sm text-primary">{{ __('Enable previews') }}</span></label><input type="number" name="preview_ttl_hours" min="1" max="720" value="{{ old('preview_ttl_hours', $project->preview_ttl_hours ?: 72) }}" class="input secondary rounded-lg" aria-label="{{ __('Lifetime in hours') }}"><input name="preview_domain" value="{{ old('preview_domain', $project->preview_domain) }}" placeholder="previews.example.com" class="input secondary rounded-lg sm:col-span-2"><x-ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Save previews') }}</x-ui.button></form>
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
    </div>
</x-layouts.app>
