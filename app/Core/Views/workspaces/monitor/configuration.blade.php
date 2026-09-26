<x-signal.layouts.platform :title="__('Monitor configuration')" :description="__('Edit mapped Monitor application, environment, and check settings.')" :navigation="[]" :account-user="$user" :current-workspace="$workspace" :workspaces="$workspaces" :context-projects="$contextProjects">
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Applications, environments, and checks')" :description="__('Core edits mapped Monitor resources and creates bounded public HTTP checks through Monitor’s native policies and actions.')">
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.monitor.alerts', $workspace)" variant="secondary">{{ __('Alert rules') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.monitor.integrations', $workspace)" variant="secondary">{{ __('Ingestion setup') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.admin', $workspace)" variant="primary">{{ __('Workspace management') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (session('status')) <x-signal.ui.alert tone="success" class="mt-5">{{ session('status') }}</x-signal.ui.alert> @endif
    @if ($errors->any()) <x-signal.ui.alert tone="danger" class="mt-5">{{ $errors->first() }}</x-signal.ui.alert> @endif

    <x-signal.ui.alert tone="info" class="mt-6">
        {{ __('Create Monitor applications and environments in Monitor, then manage their current Core mappings here. For active mapped environments, Core can create an enabled HTTP GET check to a public health URL; credentials, query strings, and fragments are not accepted. Ingestion tokens, authenticated probes, other check types, deletion, and restoration stay in Monitor or the credential inventory.') }}
    </x-signal.ui.alert>

    <section aria-labelledby="monitor-applications-heading" class="mt-8">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div><p class="ui-eyebrow">{{ __('Mapped project resources') }}</p><h2 id="monitor-applications-heading" class="mt-1 text-xl font-extrabold text-ink">{{ __('Applications') }}</h2></div>
            <form method="GET" action="{{ route('core.workspace.monitor.configuration.index', $workspace) }}" class="flex flex-wrap items-end gap-2">
                @foreach (['environment_search', 'check_search'] as $filter)<x-signal.ui.input type="hidden" :name="$filter" :value="request($filter)" />@endforeach
                <x-signal.ui.input-field id="monitor-application-search" name="application_search" :label="__('Find applications')" :value="request('application_search')" maxlength="100" />
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Search') }}</x-signal.ui.button>
            </form>
        </div>
        <div class="mt-4 grid gap-4">
            @forelse ($snapshot->applications as $application)
                <x-signal.ui.card class="p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div><p class="ui-eyebrow">{{ $application['project'] }} · {{ $application['framework'] }}{{ $application['framework_version'] !== '' ? ' '.$application['framework_version'] : '' }}</p><h3 class="mt-1 text-lg font-extrabold text-ink">{{ $application['name'] }}</h3></div>
                        <span class="text-xs text-muted">{{ __('Mapped to this Core project') }}</span>
                    </div>
                    @if ($application['can_update'])
                    <form method="POST" action="{{ route('core.workspace.monitor.configuration.applications.update', $workspace) }}" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        @csrf @method('PUT')<x-signal.ui.input type="hidden" name="application_reference" :value="$application['reference']" />
                        <x-signal.ui.input-field :id="'monitor-app-'.$loop->index.'-name'" name="name" :label="__('Application name')" :value="$application['name']" maxlength="120" required :restore="false" />
                        <x-signal.ui.input-field :id="'monitor-app-'.$loop->index.'-framework'" name="framework" :label="__('Framework')" :value="$application['framework']" maxlength="80" required :restore="false" />
                        <x-signal.ui.input-field :id="'monitor-app-'.$loop->index.'-framework-version'" name="framework_version" :label="__('Framework version')" :value="$application['framework_version']" maxlength="40" :restore="false" />
                        <x-signal.ui.select-field :id="'monitor-app-'.$loop->index.'-accent'" name="accent" :label="__('Accent')">@foreach (\App\Modules\Monitor\Models\Application::ACCENTS as $accent)<option value="{{ $accent }}" @selected($application['accent'] === $accent)>{{ ucfirst($accent) }}</option>@endforeach</x-signal.ui.select-field>
                        <x-signal.ui.button type="submit" variant="primary" class="self-end">{{ __('Save application') }}</x-signal.ui.button>
                    </form>
                    @else
                        <p class="mt-3 text-sm text-muted">{{ __('Your Monitor role can view this application but cannot change it.') }}</p>
                    @endif
                </x-signal.ui.card>
            @empty
                <x-signal.ui.empty-state :title="__('No mapped Monitor applications')" :description="__('Create or link an application in Monitor, then return here to edit its mapped configuration.')" icon="layers" />
            @endforelse
        </div>
        @if ($snapshot->applications->hasPages())<nav class="mt-4" aria-label="{{ __('Monitor application pages') }}">{{ $snapshot->applications->links() }}</nav>@endif
    </section>

    <section aria-labelledby="monitor-environments-heading" class="mt-10">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div><p class="ui-eyebrow">{{ __('Mapped project environments') }}</p><h2 id="monitor-environments-heading" class="mt-1 text-xl font-extrabold text-ink">{{ __('Environments') }}</h2></div>
            <form method="GET" action="{{ route('core.workspace.monitor.configuration.index', $workspace) }}" class="flex flex-wrap items-end gap-2">
                @foreach (['application_search', 'check_search'] as $filter)<x-signal.ui.input type="hidden" :name="$filter" :value="request($filter)" />@endforeach
                <x-signal.ui.input-field id="monitor-environment-search" name="environment_search" :label="__('Find environments')" :value="request('environment_search')" maxlength="100" />
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Search') }}</x-signal.ui.button>
            </form>
        </div>
        <div class="mt-4 grid gap-4">
            @forelse ($snapshot->environments as $environment)
                <x-signal.ui.card class="p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="ui-eyebrow">{{ $environment['project'] }} · {{ $environment['application'] }} · {{ ucfirst($environment['status']) }}</p><h3 class="mt-1 text-lg font-extrabold text-ink">{{ $environment['name'] }}</h3></div><span class="text-xs text-muted">{{ $environment['slug'] }}</span></div>
                    @if ($environment['can_update'])
                    <form method="POST" action="{{ route('core.workspace.monitor.configuration.environments.update', $workspace) }}" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        @csrf @method('PUT')<x-signal.ui.input type="hidden" name="environment_reference" :value="$environment['reference']" />
                        <x-signal.ui.input-field :id="'monitor-env-'.$loop->index.'-name'" name="name" :label="__('Environment name')" :value="$environment['name']" maxlength="120" required :restore="false" />
                        <x-signal.ui.input-field :id="'monitor-env-'.$loop->index.'-slug'" name="slug" :label="__('Slug')" :value="$environment['slug']" maxlength="80" required :restore="false" />
                        <x-signal.ui.select-field :id="'monitor-env-'.$loop->index.'-status'" name="status" :label="__('Ingestion')"><option value="active" @selected($environment['status'] === 'active')>{{ __('Active') }}</option><option value="paused" @selected($environment['status'] === 'paused')>{{ __('Paused') }}</option></x-signal.ui.select-field>
                        <x-signal.ui.button type="submit" variant="primary" class="self-end">{{ __('Save environment') }}</x-signal.ui.button>
                    </form>
                    @else
                        <p class="mt-3 text-sm text-muted">{{ __('Your Monitor role can view this environment but cannot change it.') }}</p>
                    @endif
                    @if ($environment['can_create_check'])
                        <x-signal.ui.disclosure :title="__('Create an HTTP check for this environment')" class="mt-4">
                            <p class="mt-3 text-xs leading-5 text-muted dark:text-subtle">{{ __('Monitor sends a public GET probe on the selected schedule. New checks expect HTTP 200–299 and use two failures to open and two successes to recover an incident.') }}</p>
                            <form method="POST" action="{{ route('core.workspace.monitor.configuration.checks.create', $workspace) }}" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                                @csrf
                                <x-signal.ui.input type="hidden" name="environment_reference" :value="$environment['reference']" />
                                <x-signal.ui.input-field :id="'monitor-env-'.$loop->index.'-check-name'" name="name" :label="__('Check name')" maxlength="120" required :restore="false" />
                                <x-signal.ui.input-field :id="'monitor-env-'.$loop->index.'-check-url'" name="request_url" :label="__('Public health URL')" type="url" maxlength="2048" placeholder="https://status.example.com/health" required :restore="false" />
                                <x-signal.ui.select-field :id="'monitor-env-'.$loop->index.'-check-interval'" name="interval_minutes" :label="__('Interval')"><option value="5" selected>{{ __('Every 5 minutes') }}</option>@foreach ([1, 15, 30, 60] as $interval)<option value="{{ $interval }}">{{ $interval === 60 ? __('Every hour') : __('Every :minutes minutes', ['minutes' => $interval]) }}</option>@endforeach</x-signal.ui.select-field>
                                <x-signal.ui.input-field :id="'monitor-env-'.$loop->index.'-check-timeout'" name="timeout_seconds" :label="__('Timeout seconds')" type="number" min="1" max="20" value="5" required :restore="false" />
                                <x-signal.ui.button type="submit" variant="primary" class="self-end">{{ __('Create HTTP check') }}</x-signal.ui.button>
                            </form>
                        </x-signal.ui.disclosure>
                    @endif
                </x-signal.ui.card>
            @empty
                <x-signal.ui.empty-state :title="__('No mapped Monitor environments')" :description="__('Mapped Monitor environments will appear here for configuration.')" icon="layers" />
            @endforelse
        </div>
        @if ($snapshot->environments->hasPages())<nav class="mt-4" aria-label="{{ __('Monitor environment pages') }}">{{ $snapshot->environments->links() }}</nav>@endif
    </section>

    <section aria-labelledby="monitor-checks-heading" class="mt-10">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div><p class="ui-eyebrow">{{ __('Existing native checks') }}</p><h2 id="monitor-checks-heading" class="mt-1 text-xl font-extrabold text-ink">{{ __('Checks') }}</h2><p class="mt-1 text-sm text-muted">{{ __('Checks are listed across all authorized mapped environments, independently of the environment page.') }}</p></div>
            <form method="GET" action="{{ route('core.workspace.monitor.configuration.index', $workspace) }}" class="flex flex-wrap items-end gap-2">
                @foreach (['application_search', 'environment_search'] as $filter)<x-signal.ui.input type="hidden" :name="$filter" :value="request($filter)" />@endforeach
                <x-signal.ui.input-field id="monitor-check-search" name="check_search" :label="__('Find checks')" :value="request('check_search')" maxlength="100" />
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Search') }}</x-signal.ui.button>
            </form>
        </div>
        <div class="mt-4 grid gap-4">
            @forelse ($snapshot->checks as $check)
                <x-signal.ui.card class="p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="ui-eyebrow">{{ $check['project'] ?? '' }}{{ $check['project'] ?? false ? ' · ' : '' }}{{ $check['application'] }} / {{ $check['environment'] }} · {{ $check['type'] }} · {{ $check['health'] }}</p><h3 class="mt-1 text-lg font-extrabold text-ink">{{ $check['name'] }}</h3></div><span class="text-xs text-muted">{{ __('Version :version', ['version' => $check['version']]) }}</span></div>
                    @if ($check['can_update'])
                        <form method="POST" action="{{ route('core.workspace.monitor.configuration.checks.update', $workspace) }}" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                            @csrf @method('PUT')<x-signal.ui.input type="hidden" name="monitor_reference" :value="$check['reference']" /><x-signal.ui.input type="hidden" name="version" :value="$check['version']" />
                            <x-signal.ui.input-field :id="'monitor-check-'.$loop->index.'-name'" name="name" :label="__('Check name')" :value="$check['name']" maxlength="120" required :restore="false" />
                            @if (! in_array($check['type_key'], ['heartbeat', 'queue'], true))
                                <x-signal.ui.select-field :id="'monitor-check-'.$loop->index.'-interval'" name="interval_minutes" :label="__('Interval')">@foreach ([1, 5, 15, 30, 60] as $interval)<option value="{{ $interval }}" @selected($check['interval_minutes'] === $interval)>{{ $interval === 60 ? __('Every hour') : __('Every :minutes minutes', ['minutes' => $interval]) }}</option>@endforeach</x-signal.ui.select-field>
                                <x-signal.ui.input-field :id="'monitor-check-'.$loop->index.'-timeout'" name="timeout_seconds" :label="__('Timeout seconds')" type="number" min="1" max="20" :value="$check['timeout_seconds']" required :restore="false" />
                                <x-signal.ui.input-field :id="'monitor-check-'.$loop->index.'-triggers'" name="trigger_checks" :label="__('Failure threshold')" type="number" min="1" max="10" :value="$check['trigger_checks']" required :restore="false" />
                                <x-signal.ui.input-field :id="'monitor-check-'.$loop->index.'-recoveries'" name="recovery_checks" :label="__('Recovery threshold')" type="number" min="1" max="10" :value="$check['recovery_checks']" required :restore="false" />
                            @endif
                            <x-signal.ui.checkbox :id="'monitor-check-'.$loop->index.'-enabled'" name="enabled" :checked="$check['enabled']" :restore="false" unchecked-value="0">{{ __('Enabled') }}</x-signal.ui.checkbox>
                            <x-signal.ui.button type="submit" variant="primary" class="self-end">{{ __('Save check') }}</x-signal.ui.button>
                        </form>
                    @else
                        <p class="mt-3 text-sm text-muted">{{ __('Your Monitor role can view this check but cannot change it.') }}</p>
                    @endif
                </x-signal.ui.card>
            @empty
                <x-signal.ui.empty-state :title="__('No mapped checks match this search')" :description="__('Create a public HTTP check from an active mapped environment above. Existing checks in authorized mapped environments can be renamed, paused, resumed, or rescheduled here.')" icon="pulse" />
            @endforelse
        </div>
        @if ($snapshot->checks->hasPages())<nav class="mt-4" aria-label="{{ __('Monitor check pages') }}">{{ $snapshot->checks->links() }}</nav>@endif
    </section>
</x-signal.layouts.platform>
