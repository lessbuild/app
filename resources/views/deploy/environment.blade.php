@php($project = $overview->project)
@php($days = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')])

<x-signal.layouts.project :overview="$overview" :title="$environment->name" :description="__('Deploy settings for this environment. Changes apply to the next deploy.')">
    @foreach (['process', 'resource', 'type', 'variables', 'maximum_replicas', 'minimum_replicas', 'start_command', 'schedule', 'task', 'cron_expression', 'replicas', 'website_id', 'name', 'hibernate_after_minutes', 'state', 'recipes', 'recipe_id'] as $key)
        @error($key)<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @endforeach
    @if ($blockReason)
        <x-signal.ui.alert tone="warning">{{ $blockReason }} {{ __('Pushes wait and deploy once allowed.') }}</x-signal.ui.alert>
    @endif

    <x-signal.ui.page-tabs :tabs="$tabs" :current="$tab" :url="route('deploy.environments.show', [$project, $environment])" />

    <x-signal.ui.page-tab-panel name="controls" :current="$tab">
    <x-signal.ui.settings-section id="controls" :title="__('Deployment controls')" :description="__('Lock deploys during an incident or freeze, or allow them only in a weekly window.')">
        <form method="POST" action="{{ route('deploy.environments.controls', [$project, $environment]) }}" class="grid items-start gap-4 p-4 sm:grid-cols-2 sm:p-6">
            @csrf
            @method('PUT')
            <div class="sm:col-span-2"><x-signal.ui.checkbox name="locked" value="1" :checked="(bool) $environment->deployment_locked_at" :disabled="! $canManage">{{ __('Lock deploys') }}</x-signal.ui.checkbox></div>
            <x-signal.ui.input-field name="lock_reason" :label="__('Reason (shown to people who try)')" :value="$environment->deployment_lock_reason" maxlength="500" />
            <div class="sm:col-span-2"><x-signal.ui.checkbox name="window" value="1" :checked="$environment->deployment_window_days !== null" :disabled="! $canManage">{{ __('Only deploy in a window') }}</x-signal.ui.checkbox></div>
            <fieldset class="flex flex-wrap gap-3 sm:col-span-2">
                <legend class="mb-2 text-sm font-bold text-ink">{{ __('Days') }}</legend>
                @foreach ($days as $number => $label)
                    <x-signal.ui.checkbox :id="'day-'.$number" name="days[]" :value="$number" :checked="in_array($number, $environment->deployment_window_days ?? [], true)">{{ $label }}</x-signal.ui.checkbox>
                @endforeach
            </fieldset>
            <x-signal.ui.input-field name="start" type="time" :label="__('From')" :value="$environment->deployment_window_start" />
            <x-signal.ui.input-field name="end" type="time" :label="__('Until')" :value="$environment->deployment_window_end" />
            <x-signal.ui.input-field name="timezone" :label="__('Time zone')" :value="$environment->deployment_window_timezone ?? 'UTC'" maxlength="64" />
            @if ($canManage)<div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="secondary">{{ __('Save controls') }}</x-signal.ui.button></div>@endif
        </form>
    </x-signal.ui.settings-section>
    </x-signal.ui.page-tab-panel>

    <x-signal.ui.page-tab-panel name="settings" :current="$tab">
    <x-signal.ui.settings-section id="settings" :title="__('How deploys run')" :description="__('Approval, strategy, safety nets, runtime and replicas.')">
        <form method="POST" action="{{ route('deploy.environments.settings', [$project, $environment]) }}" class="grid items-start gap-4 p-4 sm:grid-cols-2 sm:p-6">
            @csrf
            @method('PUT')
            <div class="grid gap-2 sm:col-span-2">
                <x-signal.ui.checkbox name="requires_deployment_approval" value="1" :checked="$environment->requires_deployment_approval">{{ __('Deploys need approval from someone else') }}</x-signal.ui.checkbox>
                <x-signal.ui.checkbox name="automatic_rollback" value="1" :checked="$environment->automatic_rollback">{{ __('Roll back automatically when a live deploy fails') }}</x-signal.ui.checkbox>
            </div>
            <x-signal.ui.select-field name="deployment_strategy" :label="__('Strategy')">
                @foreach (['blue_green' => __('Blue-green (switch when ready)'), 'canary' => __('Canary (check the new release first)'), 'rolling' => __('Rolling (restart workers one by one)')] as $value => $label)
                    <option value="{{ $value }}" @selected($environment->deployment_strategy === $value)>{{ $label }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.input-field name="rolling_pause_seconds" type="number" min="0" max="30" :label="__('Pause between workers (seconds)')" :value="$environment->rolling_pause_seconds" />
            <x-signal.ui.select-field name="post_deployment_observation_minutes" :label="__('Watch health after each deploy')">
                <option value="">{{ __('Don’t watch') }}</option>
                @foreach ([5, 10, 15, 30] as $minutes)
                    <option value="{{ $minutes }}" @selected($environment->post_deployment_observation_minutes === $minutes)>{{ trans_choice(':count minute|:count minutes', $minutes) }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.select-field name="runtime_type" :label="__('Runtime')">
                @foreach (['php' => 'PHP', 'node' => 'Node.js', 'python' => 'Python', 'docker' => 'Docker'] as $value => $label)
                    <option value="{{ $value }}" @selected($environment->runtime_type === $value)>{{ $label }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.input-field name="runtime_version" :label="__('Version (optional)')" :value="$environment->runtime_version" placeholder="22" maxlength="20" />
            <x-signal.ui.input-field name="container_port" type="number" min="1" max="65535" :label="__('App port (Node, Python, Docker)')" :value="$environment->container_port" />
            <x-signal.ui.input-field name="build_command" :label="__('Build command')" :value="$environment->build_command" maxlength="2000" />
            <x-signal.ui.input-field name="start_command" :label="__('Start command')" :value="$environment->start_command" placeholder="node server.js" maxlength="2000" />
            <x-signal.ui.input-field name="dockerfile_path" :label="__('Dockerfile')" :value="$environment->dockerfile_path" placeholder="Dockerfile" maxlength="255" />
            <div class="grid grid-cols-3 gap-3 sm:col-span-2">
                <x-signal.ui.input-field name="minimum_replicas" type="number" min="1" max="20" :label="__('Min replicas')" :value="$environment->minimum_replicas" />
                <x-signal.ui.input-field name="desired_replicas" type="number" min="1" max="20" :label="__('Running')" :value="$environment->desired_replicas" />
                <x-signal.ui.input-field name="maximum_replicas" type="number" min="1" max="20" :label="__('Max replicas')" :value="$environment->maximum_replicas" />
            </div>
            @if ($canManage)<div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Save settings') }}</x-signal.ui.button></div>@endif
        </form>
    </x-signal.ui.settings-section>
    </x-signal.ui.page-tab-panel>

    <x-signal.ui.page-tab-panel name="variables" :current="$tab">
    <x-signal.ui.settings-section id="variables" :title="__('Variables')" :description="__('Written into .env on each deploy (runtime), exported while building (build), or both. Secrets aren’t shown again.')">
        <div class="grid gap-4 p-4 sm:p-6">
            @if ($environment->variables->isNotEmpty())
                <ul class="divide-y divide-line text-sm">
                    @foreach ($environment->variables as $variable)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                            <span class="min-w-0 break-all"><span class="font-mono font-bold">{{ $variable->key }}</span> <span class="font-mono text-muted">= {{ $variable->is_secret ? '••••••••' : \Illuminate\Support\Str::limit($variable->value, 60) }}</span> <span class="text-xs text-muted">· {{ __(\App\Models\EnvironmentVariable::SCOPES[$variable->scope] ?? $variable->scope) }} · v{{ $variable->current_version }}@if ($variable->rotation_due_at) · {{ __('rotate by :date', ['date' => $variable->rotation_due_at->toFormattedDateString()]) }}@endif</span></span>
                            @if ($canManage)
                                <form method="POST" action="{{ route('deploy.environments.settings.destroy', [$project, $environment, 'variables', $variable->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($canManage)
                <div><x-signal.ui.button :href="route('deploy.environments.show', [$project, $environment, 'tab' => 'variables', 'dialog' => 'add-variable'])" variant="secondary" data-modal-trigger="add-variable">{{ __('Add a variable') }}</x-signal.ui.button></div>
                <x-signal.overlays.modal id="add-variable" :title="__('Add a variable to :environment', ['environment' => $environment->name])" :description="__('Saving a key that exists makes a new version of it. Secrets are encrypted and never shown again.')">
                <form method="POST" action="{{ route('deploy.environments.variables.store', [$project, $environment]) }}" class="grid items-start gap-4 sm:grid-cols-2">
                    @csrf
                    <input type="hidden" name="_modal" value="add-variable">
                    <x-signal.ui.input-field name="key" :label="__('Key')" placeholder="STRIPE_SECRET" maxlength="255" required />
                    <x-signal.ui.input-field name="value" :label="__('Value')" autocomplete="off" :restore="false" />
                    <x-signal.ui.select-field name="scope" :label="__('Used for')">
                        @foreach (\App\Models\EnvironmentVariable::SCOPES as $value => $label)
                            <option value="{{ $value }}">{{ __($label) }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <x-signal.ui.input-field name="rotation_due_at" type="date" :label="__('Rotate by (optional)')" />
                    <div class="sm:col-span-2"><x-signal.ui.checkbox name="is_secret" value="1" :checked="true">{{ __('Secret (hide the value)') }}</x-signal.ui.checkbox></div>
                    <div class="flex justify-end sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Save variable') }}</x-signal.ui.button></div>
                </form>
                </x-signal.overlays.modal>
                <x-signal.ui.disclosure :title="__('Replace all from a .env file')">
                    <form method="POST" action="{{ route('deploy.environments.variables.replace', [$project, $environment]) }}" class="grid gap-3">
                        @csrf
                        @method('PUT')
                        <x-signal.ui.textarea-field name="variables" :label="__('KEY=value lines')" rows="6" :description="__('Every variable not listed is removed.')" />
                        <div><x-signal.ui.button type="submit" variant="secondary">{{ __('Replace variables') }}</x-signal.ui.button></div>
                    </form>
                </x-signal.ui.disclosure>
            @endif
        </div>
    </x-signal.ui.settings-section>
    </x-signal.ui.page-tab-panel>

    <x-signal.ui.page-tab-panel name="processes" :current="$tab">
    <x-signal.ui.settings-section id="processes" :title="__('Workers and scheduler')" :description="__('Long-running processes each deploy restarts as systemd units, like queue workers or the scheduler.')">
        <div class="grid gap-4 p-4 sm:p-6">
            @foreach ($environment->processes as $process)
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span><span class="font-bold">{{ $process->name }}</span> <span class="font-mono text-xs text-muted">{{ $process->command }}</span> <span class="text-xs text-muted">· {{ $process->type }} · ×{{ $process->replicas }}@unless ($process->is_enabled) · {{ __('off') }}@endunless</span></span>
                    @if ($canManage)
                        <form method="POST" action="{{ route('deploy.environments.settings.destroy', [$project, $environment, 'processes', $process->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                    @endif
                </div>
            @endforeach
            @if ($canManage)
                <form method="POST" action="{{ route('deploy.environments.processes.store', [$project, $environment]) }}" class="grid items-start gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:grid-cols-3">
                    @csrf
                    <x-signal.ui.input-field id="process-name" name="name" :label="__('Name')" placeholder="queue" maxlength="60" required />
                    <x-signal.ui.select-field id="process-type" name="type" :label="__('Type')"><option value="worker">{{ __('Worker') }}</option><option value="scheduler">{{ __('Scheduler') }}</option></x-signal.ui.select-field>
                    <x-signal.ui.input-field id="process-replicas" name="replicas" type="number" min="1" max="20" :label="__('Replicas')" value="1" required />
                    <div class="sm:col-span-3"><x-signal.ui.input-field id="process-command" name="command" :label="__('Command')" placeholder="php artisan queue:work --tries=3" maxlength="2000" required /></div>
                    <x-signal.ui.select-field id="process-restart" name="restart_policy" :label="__('Restart')"><option value="always">{{ __('Always') }}</option><option value="on-failure">{{ __('On failure') }}</option></x-signal.ui.select-field>
                    <x-signal.ui.input-field id="process-delay" name="restart_delay_seconds" type="number" min="0" max="300" :label="__('Restart delay (s)')" value="5" required />
                    <div class="self-end"><x-signal.ui.button type="submit" variant="secondary">{{ __('Save process') }}</x-signal.ui.button></div>
                </form>
            @endif
        </div>
    </x-signal.ui.settings-section>
    </x-signal.ui.page-tab-panel>

    <x-signal.ui.page-tab-panel name="resources" :current="$tab">
    <x-signal.ui.settings-section id="resources" :title="__('Resources')" :description="__('Databases, caches and storage. Their variables go into .env; managed Redis and Valkey are set up on the server.')">
        <div class="grid gap-4 p-4 sm:p-6">
            @foreach ($environment->resources as $resource)
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span><span class="font-bold">{{ $resource->name }}</span> <span class="text-xs text-muted">· {{ \App\Models\EnvironmentResource::TYPES[$resource->type] ?? $resource->type }} · {{ $resource->is_managed ? __('managed') : __('external') }} · {{ implode(', ', array_keys($resource->configuration['variables'] ?? [])) }}</span></span>
                    @if ($canManage)
                        <form method="POST" action="{{ route('deploy.environments.settings.destroy', [$project, $environment, 'resources', $resource->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                    @endif
                </div>
            @endforeach
            @if ($canManage)
                <form method="POST" action="{{ route('deploy.environments.resources.store', [$project, $environment]) }}" class="grid items-start gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:grid-cols-2">
                    @csrf
                    <x-signal.ui.input-field id="resource-name" name="name" :label="__('Name')" placeholder="cache" maxlength="60" required />
                    <x-signal.ui.select-field id="resource-type" name="type" :label="__('Type')">
                        @foreach (\App\Models\EnvironmentResource::TYPES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <div class="sm:col-span-2"><x-signal.ui.checkbox id="resource-managed" name="is_managed" value="1">{{ __('Managed (MySQL uses the website’s database; Redis and Valkey run on the server)') }}</x-signal.ui.checkbox></div>
                    <div class="sm:col-span-2"><x-signal.ui.textarea-field id="resource-variables" name="variables" :label="__('Variables for an external service')" rows="3" :description="__('KEY=value lines, e.g. AWS_BUCKET=assets.')" /></div>
                    <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="secondary">{{ __('Save resource') }}</x-signal.ui.button></div>
                </form>
            @endif
        </div>
    </x-signal.ui.settings-section>
    </x-signal.ui.page-tab-panel>

    <x-signal.ui.page-tab-panel name="automation" :current="$tab">
    <x-signal.ui.settings-section id="hibernation" :title="__('Hibernation')" :description="__('After a while without requests, Laravel apps go into maintenance mode and workers stop; the next request wakes them within a minute. Deploys and scaling wake them too.')">
        <div class="grid gap-4 p-4 sm:p-6">
            <p class="text-sm">
                @if ($environment->hibernated_at)
                    <x-signal.ui.badge tone="info">{{ __('Hibernating') }}</x-signal.ui.badge> <span class="text-muted">{{ __('since :when', ['when' => $environment->hibernated_at->diffForHumans()]) }}</span>
                @else
                    <x-signal.ui.badge tone="success">{{ __('Running') }}</x-signal.ui.badge> <span class="text-muted">{{ trans_choice(':count replica|:count replicas', $environment->desired_replicas) }}@if ($environment->last_activity_at) · {{ __('last activity :when', ['when' => $environment->last_activity_at->diffForHumans()]) }}@endif</span>
                @endif
            </p>
            @if ($canManage)
                <form method="POST" action="{{ route('deploy.environments.hibernation', [$project, $environment]) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    @method('PUT')
                    <x-signal.ui.select-field name="hibernate_after_minutes" :label="__('Hibernate after')" :disabled="! $plan['hibernation']" :description="$plan['hibernation'] ? null : __('Hibernation comes with the Starter Deploy plan and above.')">
                        <option value="">{{ __('Never') }}</option>
                        @foreach (\App\Actions\Deploy\UpdateEnvironmentHibernation::MINUTES as $minutes)
                            <option value="{{ $minutes }}" @selected($environment->hibernate_after_minutes === $minutes)>{{ $minutes >= 60 ? trans_choice(':count hour without requests|:count hours without requests', intdiv($minutes, 60)) : trans_choice(':count minute without requests|:count minutes without requests', $minutes) }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <x-signal.ui.button type="submit" variant="secondary" :disabled="! $plan['hibernation']">{{ __('Save') }}</x-signal.ui.button>
                </form>
                <form method="POST" action="{{ route('deploy.environments.runtime', [$project, $environment]) }}">
                    @csrf
                    <input type="hidden" name="state" value="{{ $environment->hibernated_at ? 'running' : 'hibernated' }}">
                    <x-signal.ui.button type="submit" variant="quiet" size="sm" :disabled="! $environment->hibernated_at && ! $plan['hibernation']">{{ $environment->hibernated_at ? __('Wake now') : __('Hibernate now') }}</x-signal.ui.button>
                </form>
            @endif
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section id="deploy-schedules" :title="__('Scheduled deploys')" :description="__('Deploy this environment’s repositories on a schedule, through its approval, lock and window.')">
        <div class="grid gap-4 p-4 sm:p-6">
            @foreach ($deploySchedules as $schedule)
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span><span class="font-bold">{{ $schedule->name }}</span> <span class="font-mono text-xs text-muted">{{ $schedule->cron_expression }} · {{ $schedule->timezone }}</span>
                        <span class="block text-xs text-muted">@if ($schedule->nextRunAt()){{ __('Next :when', ['when' => $schedule->nextRunAt()?->diffForHumans()]) }}@endif @if ($schedule->last_result) · {{ __('Last: :result', ['result' => $schedule->last_result]) }}@endif</span></span>
                    @if ($canManage)
                        <form method="POST" action="{{ route('deploy.environments.settings.destroy', [$project, $environment, 'deployment-schedules', $schedule->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                    @endif
                </div>
            @endforeach
            @if ($canManage && $plan['scheduled'])
                <form method="POST" action="{{ route('deploy.environments.deployment-schedules.store', [$project, $environment]) }}" class="grid items-start gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:grid-cols-3">
                    @csrf
                    <x-signal.ui.input-field id="deploy-schedule-name" name="name" :label="__('Name')" placeholder="Nightly" maxlength="100" required />
                    <x-signal.ui.input-field id="deploy-schedule-cron" name="cron_expression" :label="__('Cron')" placeholder="0 3 * * *" maxlength="100" required />
                    <x-signal.ui.input-field id="deploy-schedule-timezone" name="timezone" :label="__('Time zone')" value="UTC" maxlength="64" required />
                    <div class="sm:col-span-3"><x-signal.ui.button type="submit" variant="secondary">{{ __('Add scheduled deploy') }}</x-signal.ui.button></div>
                </form>
            @elseif (! $plan['scheduled'])
                <p class="text-sm text-muted">{{ __('Scheduled deploys and tasks come with the Pro Deploy plan and above.') }}</p>
            @endif
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section id="scaling-schedules" :title="__('Scaling schedules')" :description="__('Run more or fewer worker replicas at set times, between :min and :max.', ['min' => $environment->minimum_replicas, 'max' => $environment->maximum_replicas])">
        <div class="grid gap-4 p-4 sm:p-6">
            @foreach ($scalingSchedules as $schedule)
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span><span class="font-bold">{{ $schedule->name }}</span> <span class="text-xs text-muted">×{{ $schedule->replicas }}</span> <span class="font-mono text-xs text-muted">{{ $schedule->cron_expression }} · {{ $schedule->timezone }}</span>
                        @if ($schedule->nextRunAt())<span class="block text-xs text-muted">{{ __('Next :when', ['when' => $schedule->nextRunAt()?->diffForHumans()]) }}</span>@endif</span>
                    @if ($canManage)
                        <form method="POST" action="{{ route('deploy.environments.settings.destroy', [$project, $environment, 'scaling-schedules', $schedule->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                    @endif
                </div>
            @endforeach
            @if ($canManage && $plan['scaling'])
                <form method="POST" action="{{ route('deploy.environments.scaling-schedules.store', [$project, $environment]) }}" class="grid items-start gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:grid-cols-4">
                    @csrf
                    <x-signal.ui.input-field id="scaling-schedule-name" name="name" :label="__('Name')" placeholder="Weekday mornings" maxlength="100" required />
                    <x-signal.ui.input-field id="scaling-schedule-replicas" name="replicas" type="number" :min="$environment->minimum_replicas" :max="$environment->maximum_replicas" :label="__('Replicas')" :value="$environment->maximum_replicas" required />
                    <x-signal.ui.input-field id="scaling-schedule-cron" name="cron_expression" :label="__('Cron')" placeholder="0 8 * * 1-5" maxlength="100" required />
                    <x-signal.ui.input-field id="scaling-schedule-timezone" name="timezone" :label="__('Time zone')" value="UTC" maxlength="64" required />
                    <div class="sm:col-span-4"><x-signal.ui.button type="submit" variant="secondary">{{ __('Add scaling schedule') }}</x-signal.ui.button></div>
                </form>
            @elseif (! $plan['scaling'])
                <p class="text-sm text-muted">{{ __('Scaling comes with the Business Deploy plan and above.') }}</p>
            @endif
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section id="tasks" :title="__('Scheduled tasks')" :description="__('Commands run in a website’s current release, as www-data with its .env, under a timeout.')">
        <div class="grid gap-4 p-4 sm:p-6">
            @foreach ($tasks as $task)
                <div class="space-y-2 border-b border-line pb-4 text-sm last:border-0 last:pb-0">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <span><span class="font-bold">{{ $task->name }}</span>
                            @if ($task->last_status)<x-signal.ui.badge :tone="$task->last_status === 'succeeded' ? 'success' : 'danger'">{{ $task->last_status === 'succeeded' ? __('Succeeded') : __('Failed') }}</x-signal.ui.badge>@endif
                            <span class="block font-mono text-xs text-muted">{{ $task->cron_expression }} · {{ $task->timezone }} · {{ $task->website->name }} · {{ trans_choice(':count second timeout|:count seconds timeout', $task->timeout_seconds) }}</span></span>
                        @if ($canManage)
                            <div class="flex gap-2">
                                <form method="POST" action="{{ route('deploy.environments.tasks.run', [$project, $environment, $task->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Run now') }}</x-signal.ui.button></form>
                                <form method="POST" action="{{ route('deploy.environments.settings.destroy', [$project, $environment, 'tasks', $task->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                            </div>
                        @endif
                    </div>
                    @if ($task->runs->isNotEmpty())
                        <ul class="text-xs text-muted">
                            @foreach ($task->runs as $run)
                                <li>{{ $run->created_at?->diffForHumans() }} · {{ __($run->status) }}@if ($run->duration_ms !== null) · {{ number_format($run->duration_ms / 1000, 1) }} s @endif @if ($run->requester) · {{ __('by :name', ['name' => $run->requester->name]) }}@endif
                                    @if ($canManage && ! $run->isActive()) · <a href="{{ route('deploy.environments.tasks.runs.show', [$project, $environment, $task->id, $run->id]) }}" class="text-primary hover:underline" target="_blank" rel="noopener">{{ __('Output') }}</a>@endif</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
            @if ($canManage && $plan['scheduled'])
                @if ($taskWebsites->isEmpty())
                    <p class="text-sm text-muted">{{ __('Connect a repository that deploys this environment to a website before adding tasks.') }}</p>
                @else
                    <form method="POST" action="{{ route('deploy.environments.tasks.store', [$project, $environment]) }}" class="grid items-start gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:grid-cols-3">
                        @csrf
                        <x-signal.ui.input-field id="task-name" name="name" :label="__('Name')" placeholder="Prune reports" maxlength="100" required />
                        <x-signal.ui.select-field id="task-website" name="website_id" :label="__('Runs in')">
                            @foreach ($taskWebsites as $website)
                                <option value="{{ $website->id }}">{{ $website->name }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.input-field id="task-timeout" name="timeout_seconds" type="number" min="10" max="3600" :label="__('Timeout (seconds)')" value="300" required />
                        <div class="sm:col-span-3"><x-signal.ui.input-field id="task-command" name="command" :label="__('Command')" placeholder="php artisan reports:prune" maxlength="2000" required /></div>
                        <x-signal.ui.input-field id="task-cron" name="cron_expression" :label="__('Cron')" placeholder="*/15 * * * *" maxlength="100" required />
                        <x-signal.ui.input-field id="task-timezone" name="timezone" :label="__('Time zone')" value="UTC" maxlength="64" required />
                        <div class="grid gap-2 self-end">
                            <x-signal.ui.checkbox id="task-overlap" name="without_overlapping" value="1" :checked="true">{{ __('Skip while the last run is going') }}</x-signal.ui.checkbox>
                            <x-signal.ui.checkbox id="task-alert" name="alert_on_failure" value="1" :checked="true">{{ __('Tell us when it fails') }}</x-signal.ui.checkbox>
                        </div>
                        <div class="sm:col-span-3"><x-signal.ui.button type="submit" variant="secondary">{{ __('Add task') }}</x-signal.ui.button></div>
                    </form>
                @endif
            @endif
        </div>
    </x-signal.ui.settings-section>
    </x-signal.ui.page-tab-panel>

    <x-signal.ui.page-tab-panel name="recipes" :current="$tab">
    <x-signal.ui.settings-section id="recipes" :title="__('Recipes')" :description="__('Scripts from your recipe library that run in this order, as root, on the servers this environment’s websites are on. Each run is in the server’s command history.')">
        <div class="grid gap-4 p-4 sm:p-6">
            @forelse ($environmentRecipes as $entry)
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span><span class="font-mono text-xs text-muted">{{ $loop->iteration }}.</span> <span class="font-bold">{{ $entry->name }}</span>
                        @if ($entry->recipe === null)<span class="text-xs text-muted">· {{ __('library recipe deleted') }}</span>@elseif ($entry->isBehind())<x-signal.ui.badge tone="warning">{{ __('Library has changes') }}</x-signal.ui.badge>@endif</span>
                    @if ($canManage)
                        <div class="flex flex-wrap gap-2">
                            @unless ($loop->first)<form method="POST" action="{{ route('deploy.environments.recipes.move', [$project, $environment, $entry->id]) }}">@csrf<input type="hidden" name="direction" value="up"><x-signal.ui.button type="submit" variant="quiet" size="sm" :aria-label="__('Move :name up', ['name' => $entry->name])">↑</x-signal.ui.button></form>@endunless
                            @unless ($loop->last)<form method="POST" action="{{ route('deploy.environments.recipes.move', [$project, $environment, $entry->id]) }}">@csrf<input type="hidden" name="direction" value="down"><x-signal.ui.button type="submit" variant="quiet" size="sm" :aria-label="__('Move :name down', ['name' => $entry->name])">↓</x-signal.ui.button></form>@endunless
                            @if ($entry->isBehind())<form method="POST" action="{{ route('deploy.environments.recipes.refresh', [$project, $environment, $entry->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Update') }}</x-signal.ui.button></form>@endif
                            <form method="POST" action="{{ route('deploy.environments.settings.destroy', [$project, $environment, 'recipes', $entry->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm text-muted">{{ __('No recipes on this environment yet.') }}</p>
            @endforelse
            @if ($canManage)
                @if ($libraryRecipes->isEmpty())
                    <p class="text-sm text-muted">{{ __('Write a recipe under Account → Recipes, or install one from the gallery, then add it here.') }} <a href="{{ route('account.recipes') }}" class="font-bold text-primary underline">{{ __('Recipes') }}</a></p>
                @else
                    <form method="POST" action="{{ route('deploy.environments.recipes.store', [$project, $environment]) }}" class="flex flex-wrap items-end gap-3 rounded-panel border border-line bg-surface-muted p-4">
                        @csrf
                        <x-signal.ui.select-field name="recipe_id" :label="__('Add a recipe')">
                            @foreach ($libraryRecipes as $recipe)
                                <option value="{{ $recipe->id }}">{{ $recipe->name }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.button type="submit" variant="secondary">{{ __('Add') }}</x-signal.ui.button>
                    </form>
                @endif
                <form method="POST" action="{{ route('deploy.environments.recipes.settings', [$project, $environment]) }}" class="flex flex-wrap items-center gap-3">
                    @csrf
                    @method('PUT')
                    <x-signal.ui.checkbox name="run_on_new_websites" value="1" :checked="$environment->recipes_run_on_new_websites">{{ __('Run them when a new website for this environment finishes setting up') }}</x-signal.ui.checkbox>
                    <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Save') }}</x-signal.ui.button>
                </form>
                @if ($environmentRecipes->isNotEmpty())
                    <form method="POST" action="{{ route('deploy.environments.recipes.run', [$project, $environment]) }}"><x-signal.ui.button type="submit" variant="primary">{{ __('Run on servers now') }}</x-signal.ui.button>@csrf</form>
                @endif
            @endif
        </div>
    </x-signal.ui.settings-section>
    </x-signal.ui.page-tab-panel>
</x-signal.layouts.project>
