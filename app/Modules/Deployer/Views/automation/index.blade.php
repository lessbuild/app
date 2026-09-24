<x-layouts.app>
    @php
        $tokenDialogHasErrors = old('_automation_token_form') === '1'
            && $errors->hasAny(['name', 'expires_in_days', 'abilities', 'abilities.0', 'abilities.1', 'abilities.2']);
        $tokenDialogOpen = (request()->query('dialog') === 'create-token' && ! session()->has('success'))
            || $tokenDialogHasErrors;
        $tokenDialogUrl = route('automation.index', ['dialog' => 'create-token']);
    @endphp

    <x-layouts.partials.heading
        eyebrow="{{ __('Release automation') }}"
        icon="terminal"
        :title="__('Automation')"
        :description="__('API access, deploy schedules, scaling and versioned workflow configuration.')"
    >
        @if ($features['api'] && $canManage)
            <x-slot:buttons>
                <x-signal.ui.button
                    href="{{ $tokenDialogUrl }}"
                    data-modal-trigger="automation-token-dialog"
                    aria-controls="automation-token-dialog"
                    aria-expanded="{{ $tokenDialogOpen ? 'true' : 'false' }}"
                    variant="primary"
                >
                    {{ __('Create token') }}
                </x-signal.ui.button>
            </x-slot:buttons>
        @endif
    </x-layouts.partials.heading>

    @if (session('success'))
        <x-signal.ui.panel class="ui-panel mt-6 border-l-4 border-line bg-surface-muted p-4 text-sm text-ink" style="border-left-color: var(--ui-success)" role="status">{{ session('success') }}</x-signal.ui.panel>
    @endif

    @if (session('plainTextToken'))
        <x-signal.ui.panel class="ui-panel mt-6 border-l-4 border-line bg-surface-muted p-4" style="border-left-color: var(--ui-warning)" role="status">
            <p class="font-bold text-ink">{{ __('Copy this token now') }}</p>
            <code class="ui-console ui-console-output mt-2 block break-all p-3 text-sm">{{ session('plainTextToken') }}</code>
        </x-signal.ui.panel>
    @endif

    @if ($errors->any())
        <x-signal.ui.panel class="ui-panel mt-6 border-l-4 border-line bg-surface-muted p-4 text-sm text-ink" style="border-left-color: var(--ui-danger)" role="alert">
            <ul class="space-y-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-signal.ui.panel>
    @endif

    @php
        $automationErrorKeys = array_keys($errors->getBag('default')->getMessages());
        $tokenPanelOpen = session('plainTextToken') || collect($automationErrorKeys)->contains(
            static fn (string $key): bool => in_array($key, ['name', 'expires_in_days'], true) || str_starts_with($key, 'abilities.'),
        );
        $environmentCount = $projects->sum(fn ($project) => $project->environments->count());
        $deploymentScheduleCount = $projects->sum(fn ($project) => $project->environments->sum(fn ($environment) => $environment->deploymentSchedules->count()));
        $scalingScheduleCount = $projects->sum(fn ($project) => $project->environments->sum(fn ($environment) => $environment->scalingSchedules->count()));
        $scheduledTaskCount = $projects->sum(fn ($project) => $project->environments->sum(fn ($environment) => $environment->scheduledTasks->count()));
        $scheduledOperationCount = $deploymentScheduleCount + $scalingScheduleCount + $scheduledTaskCount;
        $scheduledTaskRuns = $projects
            ->flatMap(fn ($project) => $project->environments)
            ->flatMap(fn ($environment) => $environment->scheduledTasks)
            ->flatMap(fn ($task) => $task->runs)
            ->values();
        $scheduledTaskRunDialogKey = request()->query('dialog');
        $scheduledTaskRunId = is_string($scheduledTaskRunDialogKey)
            && preg_match('/^scheduled-task-run-(\d+)$/', $scheduledTaskRunDialogKey, $matches) === 1
            ? (int) $matches[1]
            : null;
        $scheduledTaskRun = $scheduledTaskRunId === null
            ? null
            : $scheduledTaskRuns->first(fn ($run) => (int) $run->id === $scheduledTaskRunId);
        $scheduledTaskRunDialogOpen = $scheduledTaskRun !== null
            && $scheduledTaskRunDialogKey === 'scheduled-task-run-'.$scheduledTaskRun->id;
    @endphp

    <x-signal.ui.insights
        id="automation-overview"
        class="mt-6 scroll-mt-24"
        data-automation-overview
        :summary="trans_choice(':count scheduled operation|:count scheduled operations', $scheduledOperationCount, ['count' => $scheduledOperationCount])"
        aria-labelledby="automation-overview-title"
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="ui-eyebrow">{{ __('Automation overview') }}</p>
                <h2 id="automation-overview-title" class="mt-1 text-xl font-extrabold text-ink">{{ __('Automate routine release work') }}</h2>
                <p class="mt-1 max-w-3xl text-sm leading-6 text-muted">{{ __('Start with API access or open an application workflow to manage deploys, capacity, runtime state and scheduled tasks.') }}</p>
            </div>
            <x-signal.ui.badge tone="{{ $features['api'] ? 'success' : 'warning' }}">{{ $features['api'] ? __('API enabled') : __('Business feature') }}</x-signal.ui.badge>
        </div>

        <div class="ui-insight-grid mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <a href="#automation-tokens" class="ui-panel block border-l-4 border-line bg-surface-muted p-4 transition hover:border-line" style="border-left-color: var(--ui-primary)" data-automation-summary="tokens">
                <p class="ui-eyebrow">{{ __('API access') }}</p>
                <p class="mt-2 text-2xl font-extrabold text-ink">{{ $tokens->count() }}</p>
                <p class="mt-1 text-sm text-muted">{{ trans_choice(':count token|:count tokens', $tokens->count(), ['count' => $tokens->count()]) }}</p>
            </a>
            <a href="#automation-workflows" class="ui-panel block border-l-4 border-line bg-surface-muted p-4 transition hover:border-line" style="border-left-color: var(--ui-primary)" data-automation-summary="applications">
                <p class="ui-eyebrow">{{ __('Applications') }}</p>
                <p class="mt-2 text-2xl font-extrabold text-ink">{{ $projects->count() }}</p>
                <p class="mt-1 text-sm text-muted">{{ trans_choice(':count workflow|:count workflows', $projects->count(), ['count' => $projects->count()]) }}</p>
            </a>
            <a href="#automation-workflows" class="ui-panel block border-l-4 border-line bg-surface-muted p-4 transition hover:border-line" style="border-left-color: var(--ui-primary)" data-automation-summary="environments">
                <p class="ui-eyebrow">{{ __('Environments') }}</p>
                <p class="mt-2 text-2xl font-extrabold text-ink">{{ $environmentCount }}</p>
                <p class="mt-1 text-sm text-muted">{{ __('Available for runtime controls') }}</p>
            </a>
            <a href="#automation-workflows" class="ui-panel block border-l-4 border-line bg-surface-muted p-4 transition hover:border-line" style="border-left-color: var(--ui-primary)" data-automation-summary="operations">
                <p class="ui-eyebrow">{{ __('Scheduled operations') }}</p>
                <p class="mt-2 text-2xl font-extrabold text-ink">{{ $scheduledOperationCount }}</p>
                <p class="mt-1 text-sm text-muted">{{ __('Deploys, scaling and tasks') }}</p>
            </a>
        </div>

        <x-signal.ui.local-nav class="mt-5" :label="__('Automation sections')">
            <a href="#automation-tokens" class="ui-local-nav__link">{{ __('API tokens') }}</a>
            <a href="#automation-quick-start" class="ui-local-nav__link">{{ __('Quick start') }}</a>
            <a href="#automation-workflows" class="ui-local-nav__link">{{ __('Application workflows') }}</a>
        </x-signal.ui.local-nav>
    </x-signal.ui.insights>

    <div class="mt-8 grid gap-5 lg:grid-cols-2">
        <x-signal.ui.panel as="details" id="automation-tokens" class="ui-responsive-details ui-panel group overflow-hidden" open data-responsive-details data-responsive-details-mobile-open="{{ $tokenPanelOpen ? 'true' : 'false' }}" data-automation-tokens>
            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 p-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus lg:hidden">
                <span>
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="font-extrabold text-ink">{{ __('Personal access tokens') }}</span>
                        <x-signal.ui.badge>{{ $tokens->count() }}</x-signal.ui.badge>
                    </span>
                    <span class="mt-1 block text-sm text-muted">{{ __('Create least-privilege Bearer tokens with an explicit expiry.') }}</span>
                </span>
                <span class="shrink-0 text-xl text-muted transition-transform group-open:rotate-45" aria-hidden="true">+</span>
            </summary>

            <div class="ui-responsive-details__content p-6 lg:block">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="ui-eyebrow">{{ __('Control plane API') }}</p>
                    <h2 class="mt-2 text-xl font-extrabold text-ink">{{ __('Personal access tokens') }}</h2>
                    <p class="mt-2 text-sm text-muted">
                        {{ __('Create least-privilege Bearer tokens with an explicit expiry.') }}
                        <a href="{{ route('api-docs') }}" class="ui-link">{{ __('API reference') }}</a>
                    </p>
                </div>
                @unless ($features['api'])
                    <x-signal.ui.badge tone="warning">{{ __('Business feature') }}</x-signal.ui.badge>
                @endunless
            </div>

            <div class="mt-6 space-y-2">
                @forelse ($tokens as $token)
                    <x-signal.ui.panel class="ui-panel flex flex-wrap items-center justify-between gap-3 bg-surface-muted p-3" data-automation-token>
                        <div class="min-w-0">
                            <p class="font-bold text-ink">{{ $token->name }}</p>
                            <div class="mt-1 flex flex-wrap items-center gap-1 text-xs text-muted">
                                @foreach ($token->abilities as $ability)
                                    <x-signal.ui.badge>{{ ucfirst($ability) }}</x-signal.ui.badge>
                                @endforeach
                                <span>{{ $token->last_used_at ? __('used :time', ['time' => $token->last_used_at->diffForHumans()]) : __('never used') }}</span>
                                <span>·</span>
                                <span>{{ $token->expires_at ? __('expires :time', ['time' => $token->expires_at->diffForHumans()]) : __('no expiry') }}</span>
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            @if ($canManage)
                                <form method="POST" action="{{ route('automation.tokens.rotate', $token->id) }}">
                                    @csrf
                                    <x-signal.ui.button type="submit" variant="secondary">{{ __('Rotate') }}</x-signal.ui.button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('automation.tokens.destroy', $token->id) }}">
                                @csrf
                                @method('DELETE')
                                <x-signal.ui.button type="submit" variant="danger">{{ __('Revoke') }}</x-signal.ui.button>
                            </form>
                        </div>
                    </x-signal.ui.panel>
                @empty
                    <x-signal.ui.empty-state :title="__('No API tokens')" :description="__('Create a token when an integration or local workflow needs API access.')" icon="key" />
                @endforelse
            </div>
            </div>
        </x-signal.ui.panel>

        @if ($features['api'] && $canManage)
            <x-scenes.automation.token-dialog :open="$tokenDialogOpen" />
        @endif

        <x-signal.ui.panel as="details" id="automation-quick-start" class="ui-responsive-details ui-panel group overflow-hidden" open data-responsive-details data-responsive-details-mobile-open="false" data-automation-quick-start>
            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 p-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus lg:hidden">
                <span>
                    <span class="block font-extrabold text-ink">{{ __('CLI-friendly API') }}</span>
                    <span class="mt-1 block text-sm font-normal text-muted">{{ __('A copy-ready starting point for curl and CI.') }}</span>
                </span>
                <span class="shrink-0 text-xl text-muted transition-transform group-open:rotate-45" aria-hidden="true">+</span>
            </summary>

            <div class="ui-responsive-details__content p-6 lg:block">
                <p class="ui-eyebrow">{{ __('Quick start') }}</p>
                <h2 class="mt-2 text-xl font-extrabold text-ink">{{ __('CLI-friendly API') }}</h2>
                <p class="mt-2 text-sm text-muted">{{ __('Everything returns JSON and works with curl, CI, or your preferred scripting language.') }}</p>
                <pre class="ui-console mt-5 overflow-x-auto p-4 text-xs leading-6"><code class="ui-console-output">export BUILDPUSHER_TOKEN="bp_…"
curl -H "Authorization: Bearer $BUILDPUSHER_TOKEN" \
  {{ url('/api/v1/projects') }}

curl -X POST -H "Authorization: Bearer $BUILDPUSHER_TOKEN" \
  {{ url('/api/v1/environments/1/deploy') }}</code></pre>
            </div>
        </x-signal.ui.panel>
    </div>

    <section id="automation-workflows" class="mt-8 scroll-mt-24" aria-labelledby="automation-workflows-title">
        <div class="mb-4">
            <p class="ui-eyebrow">{{ __('Release controls') }}</p>
            <h2 id="automation-workflows-title" class="mt-1 text-2xl font-extrabold text-ink">{{ __('Application workflows') }}</h2>
            <p class="mt-1 text-muted">{{ __('Open an application to configure it. This keeps a large workspace compact.') }}</p>
        </div>

        <div class="space-y-4">
            @forelse ($projects as $project)
                <details id="automation-project-{{ $project->id }}" class="ui-panel group overflow-hidden" data-automation-project>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5">
                        <div>
                            <p class="font-extrabold text-ink">{{ $project->name }}</p>
                            <p class="mt-1 text-sm text-muted">
                                {{ trans_choice(':count environment|:count environments', $project->environments->count(), ['count' => $project->environments->count()]) }}
                                · {{ $project->environments->sum(fn ($environment) => $environment->deploymentSchedules->count()) }} {{ __('deploy schedules') }}
                                · {{ $project->environments->sum(fn ($environment) => $environment->scheduledTasks->count()) }} {{ __('tasks') }}
                            </p>
                        </div>
                        <span class="text-2xl text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
                    </summary>

                    <div class="border-t border-line p-5">
                        <form method="POST" action="{{ route('automation.workflow', $project) }}">
                            @csrf
                            @method('PUT')
                            <label class="ui-label" for="workflow-{{ $project->id }}">buildpusher.yaml</label>
                            <x-signal.ui.textarea id="workflow-{{ $project->id }}" name="workflow" rows="12" class="ui-input mt-2 w-full font-mono text-xs" spellcheck="false" :restore="false">{{ old('workflow', $project->workflow_yaml ?: "version: 1\nenvironments:\n  production:\n    deployment:\n      cron: '0 3 * * 1-5'\n      timezone: UTC\n    scale:\n      minimum: 1\n      maximum: 3\n      desired: 2\n      hibernate_after_minutes: 60") }}</x-signal.ui.textarea>
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                                <p class="text-xs text-muted">{{ __('Applying YAML validates every setting before saving any of them.') }}</p>
                                <x-signal.ui.button type="submit" variant="primary" :disabled="! $canManage">{{ __('Validate & apply') }}</x-signal.ui.button>
                            </div>
                        </form>

                        <div class="mt-6 grid gap-4 xl:grid-cols-2">
                            @foreach ($project->environments as $environment)
                                <x-signal.ui.panel as="article" class="ui-panel bg-surface-muted p-4" data-automation-environment>
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <h3 class="truncate font-extrabold text-ink">{{ $environment->name }}</h3>
                                            <p class="text-xs text-muted">{{ $environment->branch }}</p>
                                        </div>
                                        <x-signal.ui.badge tone="{{ $environment->hibernated_at ? 'warning' : 'success' }}">{{ $environment->hibernated_at ? __('Hibernated') : __('Running') }}</x-signal.ui.badge>
                                    </div>
                                    @if ($features['hibernation'])
                                        <form method="POST" action="{{ route('automation.runtime', $environment) }}" class="mt-3">
                                            @csrf
                                            @method('PATCH')
                                            <x-signal.ui.input type="hidden" name="state" value="{{ $environment->hibernated_at ? 'running' : 'hibernated' }}" :restore="false" />
                                            <x-signal.ui.button type="submit" variant="secondary">{{ $environment->hibernated_at ? __('Resume') : __('Hibernate') }}</x-signal.ui.button>
                                        </form>
                                    @endif

                                    @if ($features['scaling'])
                                        <form method="POST" action="{{ route('automation.scale', $environment) }}" class="mt-5 grid grid-cols-3 gap-2 border-t border-line pt-4">
                                            @csrf
                                            @method('PATCH')
                                            <label class="block"><span class="sr-only">{{ __('Minimum replicas') }}</span><x-signal.ui.input class="ui-input w-full" type="number" min="1" max="20" name="minimum_replicas" value="{{ $environment->minimum_replicas }}" aria-label="{{ __('Minimum replicas') }}" :restore="false" /></label>
                                            <label class="block"><span class="sr-only">{{ __('Maximum replicas') }}</span><x-signal.ui.input class="ui-input w-full" type="number" min="1" max="20" name="maximum_replicas" value="{{ min(20, $environment->maximum_replicas) }}" aria-label="{{ __('Maximum replicas') }}" :restore="false" /></label>
                                            <label class="block"><span class="sr-only">{{ __('Desired replicas') }}</span><x-signal.ui.input class="ui-input w-full" type="number" min="1" max="20" name="desired_replicas" value="{{ $environment->desired_replicas }}" aria-label="{{ __('Desired replicas') }}" :restore="false" /></label>
                                            <x-signal.ui.input type="hidden" name="hibernate_after_minutes" value="{{ $environment->hibernate_after_minutes }}" :restore="false" />
                                            <x-signal.ui.button type="submit" variant="primary" class="col-span-3">{{ __('Apply capacity') }}</x-signal.ui.button>
                                        </form>
                                    @else
                                        <p class="mt-4 text-sm text-muted"><a class="ui-link" href="{{ route('billing.index') }}">{{ __('Upgrade to Business') }}</a> {{ __('for scheduled scaling.') }}</p>
                                    @endif

                                    <div class="mt-5 border-t border-line pt-4" data-automation-schedules>
                                        <p class="ui-eyebrow">{{ __('Schedules') }}</p>
                                        @foreach ($environment->deploymentSchedules as $schedule)
                                            <div class="mt-2 flex items-center justify-between gap-3 text-xs text-muted">
                                                <span class="min-w-0 truncate">{{ $schedule->name }} · <code>{{ $schedule->cron_expression }}</code></span>
                                                <form method="POST" action="{{ route('automation.deployment-schedules.destroy', $schedule) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <x-signal.ui.button type="submit" variant="danger" aria-label="{{ __('Delete :name', ['name' => $schedule->name]) }}">×</x-signal.ui.button>
                                                </form>
                                            </div>
                                        @endforeach

                                    @if ($features['scheduled_deployments'])
                                            @php
                                                $scheduleDialogId = 'automation-schedule-dialog-'.$environment->id;
                                                $scheduleDialogKey = 'deployment-schedule-'.$environment->id;
                                                $scheduleDialogOpen = request()->query('dialog') === $scheduleDialogKey
                                                    || old('_automation_dialog') === $scheduleDialogKey;
                                            @endphp
                                            <x-signal.ui.button
                                                href="{{ route('automation.index', ['dialog' => $scheduleDialogKey]) }}"
                                                data-modal-trigger="{{ $scheduleDialogId }}"
                                                aria-controls="{{ $scheduleDialogId }}"
                                                aria-expanded="{{ $scheduleDialogOpen ? 'true' : 'false' }}"
                                                variant="secondary"
                                                class="mt-4"
                                            >
                                                {{ __('Add schedule') }}
                                            </x-signal.ui.button>

                                            <x-scenes.automation.schedule-dialog
                                                :environment="$environment"
                                                :dialog-key="$scheduleDialogKey"
                                                :open="$scheduleDialogOpen"
                                            />
                                        @endif
                                    </div>

                                    <div class="mt-5 border-t border-line pt-4" data-automation-tasks>
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="ui-eyebrow">{{ __('Application tasks') }}</p>
                                            <x-signal.ui.badge>{{ __('Encrypted commands') }}</x-signal.ui.badge>
                                        </div>
                                        <div class="mt-3 space-y-2">
                                            @foreach ($environment->scheduledTasks as $task)
                                                <x-signal.ui.panel class="ui-panel bg-surface p-3" data-automation-task>
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <div class="min-w-0 flex-1">
                                                            <p class="font-bold text-ink">{{ $task->name }}</p>
                                                            <p class="text-xs text-muted"><code>{{ $task->cron_expression }}</code> · {{ $task->timezone }} · {{ $task->last_status ? ucfirst($task->last_status) : __('Never run') }}</p>
                                                        </div>
                                                        <form method="POST" action="{{ route('automation.tasks.run', $task) }}">
                                                            @csrf
                                                            <x-signal.ui.button type="submit" variant="secondary">{{ __('Run') }}</x-signal.ui.button>
                                                        </form>
                                                        <form method="POST" action="{{ route('automation.tasks.destroy', $task) }}">
                                                            @csrf
                                                            @method('DELETE')
                                                            <x-signal.ui.button type="submit" variant="danger">{{ __('Delete') }}</x-signal.ui.button>
                                                        </form>
                                                    </div>
                                                            @if ($task->runs->isNotEmpty())
                                                        <div class="mt-2 flex flex-wrap gap-2">
                                                            @foreach ($task->runs->take(5) as $run)
                                                                @php
                                                                    $taskRunDialogKey = 'scheduled-task-run-'.$run->id;
                                                                @endphp
                                                                <a
                                                                    href="{{ route('automation.task-runs.output', $run) }}"
                                                                    data-modal-trigger="automation-task-run-dialog"
                                                                    data-modal-content-url="{{ route('automation.task-runs.output', ['run' => $run, 'fragment' => 'scheduled-task-output']) }}"
                                                                    data-modal-history-url="{{ route('automation.index', ['dialog' => $taskRunDialogKey]) }}"
                                                                    aria-controls="automation-task-run-dialog"
                                                                    aria-expanded="{{ $scheduledTaskRunDialogOpen && $scheduledTaskRun->id === $run->id ? 'true' : 'false' }}"
                                                                    class="ui-chip text-[10px] transition-colors hover:border-[var(--ui-primary)] hover:bg-[var(--ui-primary-soft)] hover:text-ink"
                                                                    data-automation-task-run
                                                                >{{ $run->status }} · {{ $run->created_at->diffForHumans() }}</a>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </x-signal.ui.panel>
                                            @endforeach
                                        </div>

                                        @php
                                            $taskDialogId = 'automation-task-dialog-'.$environment->id;
                                            $taskDialogKey = 'scheduled-task-'.$environment->id;
                                            $taskDialogOpen = request()->query('dialog') === $taskDialogKey
                                                || old('_automation_dialog') === $taskDialogKey;
                                        @endphp
                                        <x-signal.ui.button
                                            href="{{ route('automation.index', ['dialog' => $taskDialogKey]) }}"
                                            data-modal-trigger="{{ $taskDialogId }}"
                                            aria-controls="{{ $taskDialogId }}"
                                            aria-expanded="{{ $taskDialogOpen ? 'true' : 'false' }}"
                                            variant="secondary"
                                            class="mt-4"
                                        >
                                            {{ __('Add task') }}
                                        </x-signal.ui.button>

                                        <x-scenes.automation.task-dialog
                                            :environment="$environment"
                                            :dialog-key="$taskDialogKey"
                                            :open="$taskDialogOpen"
                                        />
                                    </div>
                                </x-signal.ui.panel>
                            @endforeach
                        </div>
                    </div>
                </details>
            @empty
                <x-signal.ui.empty-state
                    :title="__('No applications')"
                    :description="__('Create an application to configure automation.')"
                    icon="terminal"
                />
            @endforelse
        </div>
    </section>

    @if ($scheduledTaskRuns->isNotEmpty())
        <x-dialogs.modal
            id="automation-task-run-dialog"
            :title="__('Scheduled task run output')"
            :description="__('Review the retained output and timing for this run without leaving Automation.')"
            :open="$scheduledTaskRunDialogOpen"
        >
            <div data-modal-content>
                <div class="space-y-3 text-sm text-muted">{{ __('Loading task-run details…') }}</div>
            </div>
        </x-dialogs.modal>
    @endif
</x-layouts.app>
