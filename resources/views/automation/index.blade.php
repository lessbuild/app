<x-layouts.app>
    <x-layouts.partials.heading
        icon="terminal"
        :title="__('Automation')"
        :description="__('API access, deploy schedules, scaling and versioned workflow configuration.')"
    />

    @if (session('success'))
        <div class="ui-alert ui-alert--success mt-6" role="status">{{ session('success') }}</div>
    @endif

    @if (session('plainTextToken'))
        <div class="ui-alert ui-alert--warning mt-6" role="status">
            <p class="font-bold text-primary">{{ __('Copy this token now') }}</p>
            <code class="mt-2 block break-all rounded-md bg-primary p-3 text-sm text-primary">{{ session('plainTextToken') }}</code>
        </div>
    @endif

    @if ($errors->any())
        <div class="ui-alert ui-alert--danger mt-6" role="alert">
            <ul class="space-y-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="mt-8 grid gap-5 lg:grid-cols-2">
        <section class="ui-card p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Control plane API') }}</p>
                    <h2 class="mt-2 text-xl font-black text-primary">{{ __('Personal access tokens') }}</h2>
                    <p class="mt-2 text-sm text-secondary">
                        {{ __('Create least-privilege Bearer tokens with an explicit expiry.') }}
                        <a href="{{ route('api-docs') }}" class="font-bold text-ternary">{{ __('API reference') }}</a>
                    </p>
                </div>
                @unless ($features['api'])
                    <x-ui.badge tone="warning">{{ __('Business feature') }}</x-ui.badge>
                @endunless
            </div>

            @if ($features['api'] && $canManage)
                <form method="POST" action="{{ route('automation.tokens.store') }}" class="mt-6 space-y-4">
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-[1fr_11rem]">
                        <label class="block">
                            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Token name') }}</span>
                            <input name="name" required maxlength="100" class="input secondary w-full rounded-md" placeholder="CI deployment">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Expires') }}</span>
                            <select name="expires_in_days" class="input secondary w-full rounded-md">
                                <option value="30">{{ __('30 days') }}</option>
                                <option value="90">{{ __('90 days') }}</option>
                                <option value="180">{{ __('180 days') }}</option>
                                <option value="365" selected>{{ __('1 year') }}</option>
                            </select>
                        </label>
                    </div>
                    <fieldset>
                        <legend class="mb-2 text-xs font-bold uppercase text-secondary">{{ __('Abilities') }}</legend>
                        <div class="flex flex-wrap gap-4 text-sm text-secondary">
                            @foreach (['read', 'deploy', 'manage'] as $ability)
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="abilities[]" value="{{ $ability }}" @checked($ability === 'read')>
                                    {{ ucfirst($ability) }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <x-ui.button type="submit" variant="primary">{{ __('Create token') }}</x-ui.button>
                </form>
            @endif

            <div class="mt-6 space-y-2">
                @forelse ($tokens as $token)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-secondary p-3">
                        <div class="min-w-0">
                            <p class="font-bold text-primary">{{ $token->name }}</p>
                            <div class="mt-1 flex flex-wrap items-center gap-1 text-xs text-secondary">
                                @foreach ($token->abilities as $ability)
                                    <x-ui.badge>{{ ucfirst($ability) }}</x-ui.badge>
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
                                    <x-ui.button type="submit" variant="secondary">{{ __('Rotate') }}</x-ui.button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('automation.tokens.destroy', $token->id) }}">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" variant="danger">{{ __('Revoke') }}</x-ui.button>
                            </form>
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state :title="__('No API tokens')" :description="__('Create a token when an integration or local workflow needs API access.')" icon="key" />
                @endforelse
            </div>
        </section>

        <section class="ui-card p-6">
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Quick start') }}</p>
            <h2 class="mt-2 text-xl font-black text-primary">{{ __('CLI-friendly API') }}</h2>
            <p class="mt-2 text-sm text-secondary">{{ __('Everything returns JSON and works with curl, CI, or your preferred scripting language.') }}</p>
            <pre class="mt-5 overflow-x-auto rounded-xl bg-gray-950 p-4 text-xs leading-6 text-gray-100"><code>export BUILDPUSHER_TOKEN="bp_…"
curl -H "Authorization: Bearer $BUILDPUSHER_TOKEN" \
  {{ url('/api/v1/projects') }}

curl -X POST -H "Authorization: Bearer $BUILDPUSHER_TOKEN" \
  {{ url('/api/v1/environments/1/deploy') }}</code></pre>
        </section>
    </div>

    <section class="mt-8">
        <div class="mb-4">
            <h2 class="text-2xl font-black text-primary">{{ __('Application workflows') }}</h2>
            <p class="mt-1 text-secondary">{{ __('Open an application to configure it. This keeps a large workspace compact.') }}</p>
        </div>

        <div class="space-y-4">
            @forelse ($projects as $project)
                <details class="ui-card group overflow-hidden">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5">
                        <div>
                            <p class="font-black text-primary">{{ $project->name }}</p>
                            <p class="mt-1 text-sm text-secondary">{{ $project->environments->count() }} {{ __('environments') }}</p>
                        </div>
                        <span class="text-2xl text-ternary transition group-open:rotate-45" aria-hidden="true">+</span>
                    </summary>

                    <div class="border-t border-primary p-5">
                        <form method="POST" action="{{ route('automation.workflow', $project) }}">
                            @csrf
                            @method('PUT')
                            <label class="block text-sm font-bold text-primary" for="workflow-{{ $project->id }}">buildpusher.yaml</label>
                            <textarea id="workflow-{{ $project->id }}" name="workflow" rows="12" class="input secondary mt-2 w-full rounded-md font-mono text-xs" spellcheck="false">{{ old('workflow', $project->workflow_yaml ?: "version: 1\nenvironments:\n  production:\n    deployment:\n      cron: '0 3 * * 1-5'\n      timezone: UTC\n    scale:\n      minimum: 1\n      maximum: 3\n      desired: 2\n      hibernate_after_minutes: 60") }}</textarea>
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                                <p class="text-xs text-secondary">{{ __('Applying YAML validates every setting before saving any of them.') }}</p>
                                <x-ui.button type="submit" variant="primary" :disabled="! $canManage">{{ __('Validate & apply') }}</x-ui.button>
                            </div>
                        </form>

                        <div class="mt-6 grid gap-4 xl:grid-cols-2">
                            @foreach ($project->environments as $environment)
                                <article class="rounded-xl border border-primary bg-secondary p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <h3 class="truncate font-black text-primary">{{ $environment->name }}</h3>
                                            <p class="text-xs text-secondary">{{ $environment->branch }}</p>
                                        </div>
                                        <x-ui.badge tone="{{ $environment->hibernated_at ? 'warning' : 'success' }}">{{ $environment->hibernated_at ? __('Hibernated') : __('Running') }}</x-ui.badge>
                                    </div>
                                    @if ($features['hibernation'])
                                        <form method="POST" action="{{ route('automation.runtime', $environment) }}" class="mt-3">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="state" value="{{ $environment->hibernated_at ? 'running' : 'hibernated' }}">
                                            <x-ui.button type="submit" variant="secondary">{{ $environment->hibernated_at ? __('Resume') : __('Hibernate') }}</x-ui.button>
                                        </form>
                                    @endif

                                    @if ($features['scaling'])
                                        <form method="POST" action="{{ route('automation.scale', $environment) }}" class="mt-5 grid grid-cols-3 gap-2 border-t border-primary pt-4">
                                            @csrf
                                            @method('PATCH')
                                            <label class="block"><span class="sr-only">{{ __('Minimum replicas') }}</span><input class="input secondary w-full rounded-md" type="number" min="1" max="20" name="minimum_replicas" value="{{ $environment->minimum_replicas }}" aria-label="{{ __('Minimum replicas') }}"></label>
                                            <label class="block"><span class="sr-only">{{ __('Maximum replicas') }}</span><input class="input secondary w-full rounded-md" type="number" min="1" max="20" name="maximum_replicas" value="{{ min(20, $environment->maximum_replicas) }}" aria-label="{{ __('Maximum replicas') }}"></label>
                                            <label class="block"><span class="sr-only">{{ __('Desired replicas') }}</span><input class="input secondary w-full rounded-md" type="number" min="1" max="20" name="desired_replicas" value="{{ $environment->desired_replicas }}" aria-label="{{ __('Desired replicas') }}"></label>
                                            <input type="hidden" name="hibernate_after_minutes" value="{{ $environment->hibernate_after_minutes }}">
                                            <x-ui.button type="submit" variant="primary" class="col-span-3">{{ __('Apply capacity') }}</x-ui.button>
                                        </form>
                                    @else
                                        <p class="mt-4 text-sm text-secondary"><a class="font-bold text-ternary" href="{{ route('billing.index') }}">{{ __('Upgrade to Business') }}</a> {{ __('for scheduled scaling.') }}</p>
                                    @endif

                                    <div class="mt-5 border-t border-primary pt-4">
                                        <p class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Schedules') }}</p>
                                        @foreach ($environment->deploymentSchedules as $schedule)
                                            <div class="mt-2 flex items-center justify-between gap-3 text-xs text-secondary">
                                                <span class="min-w-0 truncate">{{ $schedule->name }} · <code>{{ $schedule->cron_expression }}</code></span>
                                                <form method="POST" action="{{ route('automation.deployment-schedules.destroy', $schedule) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <x-ui.button type="submit" variant="danger" aria-label="{{ __('Delete :name', ['name' => $schedule->name]) }}">×</x-ui.button>
                                                </form>
                                            </div>
                                        @endforeach

                                        @if ($features['scheduled_deployments'])
                                            <form method="POST" action="{{ route('automation.deployment-schedules.store', $environment) }}" class="mt-4 grid gap-3 sm:grid-cols-3">
                                                @csrf
                                                <label class="block"><span class="sr-only">{{ __('Schedule name') }}</span><input required name="name" class="input secondary w-full rounded-md text-xs" placeholder="Nightly"></label>
                                                <label class="block"><span class="sr-only">{{ __('Cron expression') }}</span><input required name="cron_expression" class="input secondary w-full rounded-md font-mono text-xs" value="0 3 * * *"></label>
                                                <label class="block"><span class="sr-only">{{ __('Timezone') }}</span><input required name="timezone" class="input secondary w-full rounded-md text-xs" value="UTC"></label>
                                                <x-ui.button type="submit" variant="secondary" class="sm:col-span-3">{{ __('Add deployment schedule') }}</x-ui.button>
                                            </form>
                                        @endif
                                    </div>

                                    <div class="mt-5 border-t border-primary pt-4">
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Application tasks') }}</p>
                                            <x-ui.badge>{{ __('Encrypted commands') }}</x-ui.badge>
                                        </div>
                                        <div class="mt-3 space-y-2">
                                            @foreach ($environment->scheduledTasks as $task)
                                                <div class="rounded-lg border border-primary bg-primary p-3">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <div class="min-w-0 flex-1">
                                                            <p class="font-bold text-primary">{{ $task->name }}</p>
                                                            <p class="text-xs text-secondary"><code>{{ $task->cron_expression }}</code> · {{ $task->timezone }} · {{ $task->last_status ? ucfirst($task->last_status) : __('Never run') }}</p>
                                                        </div>
                                                        <form method="POST" action="{{ route('automation.tasks.run', $task) }}">
                                                            @csrf
                                                            <x-ui.button type="submit" variant="secondary">{{ __('Run') }}</x-ui.button>
                                                        </form>
                                                        <form method="POST" action="{{ route('automation.tasks.destroy', $task) }}">
                                                            @csrf
                                                            @method('DELETE')
                                                            <x-ui.button type="submit" variant="danger">{{ __('Delete') }}</x-ui.button>
                                                        </form>
                                                    </div>
                                                    @if ($task->runs->isNotEmpty())
                                                        <div class="mt-2 flex flex-wrap gap-2">
                                                            @foreach ($task->runs->take(5) as $run)
                                                                <a href="{{ route('automation.task-runs.output', $run) }}" class="rounded-md bg-secondary px-2 py-1 text-[10px] text-secondary">{{ $run->status }} · {{ $run->created_at->diffForHumans() }}</a>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>

                                        <form method="POST" action="{{ route('automation.tasks.store', $environment) }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                                            @csrf
                                            <label class="block"><span class="sr-only">{{ __('Task name') }}</span><input name="name" maxlength="100" required class="input secondary w-full rounded-md text-xs" placeholder="Warm cache"></label>
                                            <label class="block"><span class="sr-only">{{ __('Cron expression') }}</span><input name="cron_expression" maxlength="100" required class="input secondary w-full rounded-md font-mono text-xs" value="0 * * * *"></label>
                                            <label class="block"><span class="sr-only">{{ __('Timezone') }}</span><input name="timezone" required class="input secondary w-full rounded-md text-xs" value="UTC"></label>
                                            <label class="block"><span class="sr-only">{{ __('Timeout seconds') }}</span><input type="number" name="timeout_seconds" min="10" max="3600" value="300" required class="input secondary w-full rounded-md text-xs" aria-label="{{ __('Timeout seconds') }}"></label>
                                            <label class="block sm:col-span-2"><span class="sr-only">{{ __('Command') }}</span><textarea name="command" maxlength="4000" required class="input secondary w-full rounded-md font-mono text-xs" rows="2" placeholder="php artisan cache:warm"></textarea></label>
                                            <input type="hidden" name="without_overlapping" value="0">
                                            <label class="flex items-center gap-2 text-xs text-secondary"><input type="checkbox" name="without_overlapping" value="1" checked>{{ __('Prevent overlap') }}</label>
                                            <input type="hidden" name="alert_on_failure" value="0">
                                            <label class="flex items-center gap-2 text-xs text-secondary"><input type="checkbox" name="alert_on_failure" value="1" checked>{{ __('Alert on failure') }}</label>
                                            <x-ui.button type="submit" variant="secondary" class="sm:col-span-2">{{ __('Add scheduled task') }}</x-ui.button>
                                        </form>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>
                </details>
            @empty
                <x-ui.empty-state
                    :title="__('No applications')"
                    :description="__('Create an application to configure automation.')"
                    icon="terminal"
                />
            @endforelse
        </div>
    </section>
</x-layouts.app>
