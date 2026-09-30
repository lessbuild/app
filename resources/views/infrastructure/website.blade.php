@php($project = $overview->project)
@php($secrets = session('secrets'))

<x-signal.layouts.project :overview="$overview" :title="$website->name" :description="$website->url.($website->server ? ' · '.$website->server->label() : '')">
    @foreach (['retry', 'domain', 'server_id', 'dns_provider_id', 'backup', 'username', 'target_website_id', 'confirmation'] as $key)
        @error($key)<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @endforeach
    @if (is_array($secrets) && isset($secrets['database']))
        <x-signal.ui.panel as="section" class="space-y-3 border-warning p-6">
            <p class="ui-eyebrow">{{ __('Copy it now') }}</p>
            <h2 class="text-lg font-extrabold text-ink">{{ __('Database password') }}</h2>
            <p class="text-sm text-muted">{{ __('Database and user :name on localhost. The password is shown once; it’s also in the server’s MySQL.', ['name' => $secrets['database_name'] ?? '']) }}</p>
            <x-signal.ui.code-block :code="$secrets['database']" class="break-all whitespace-pre-wrap" />
        </x-signal.ui.panel>
    @endif

    @if (is_array($secrets) && isset($secrets['database_user']))
        <x-signal.ui.panel as="section" class="space-y-3 border-warning p-6">
            <p class="ui-eyebrow">{{ __('Copy it now') }}</p>
            <h2 class="text-lg font-extrabold text-ink">{{ __('Password for :user', ['user' => $secrets['database_user_name'] ?? '']) }}</h2>
            <p class="text-sm text-muted">{{ __('It’s shown once. Connect to :database on localhost (through an SSH tunnel from elsewhere).', ['database' => $website->databaseIdentifier()]) }}</p>
            <x-signal.ui.code-block :code="$secrets['database_user']" class="break-all whitespace-pre-wrap" />
        </x-signal.ui.panel>
    @endif

    <x-signal.ui.card class="grid gap-4 p-5">
        <div class="flex flex-wrap items-center gap-3">
            @include('infrastructure._website-status', ['website' => $website])
            @if ($website->isProvisioning())
                <span class="text-sm text-muted">{{ __('Stage :stage of :final', ['stage' => $website->setup_stage, 'final' => $finalStage]) }}</span>
            @endif
            <a href="https://{{ $website->url }}" target="_blank" rel="noopener" class="text-sm font-bold text-primary hover:underline">{{ $website->url }}</a>
        </div>
        @if ($website->provisioning_status === 'failed')
            <p class="text-sm text-danger">{{ $website->provisioning_error }}</p>
        @endif
        @if ($website->placement_cleanup_error)
            <p class="text-sm text-danger">{{ __('The copy on the previous server couldn’t be removed: :error', ['error' => $website->placement_cleanup_error]) }}</p>
        @endif
        @if ($canManage && ($website->provisioning_status === 'failed' || $website->placement_cleanup_error))
            <form method="POST" action="{{ route('infrastructure.websites.retry', [$project, $website->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Retry') }}</x-signal.ui.button></form>
        @endif
        <dl class="grid gap-4 text-sm sm:grid-cols-3">
            <div><dt class="text-xs text-muted">{{ __('Directory') }}</dt><dd class="mt-1 font-mono">/var/www/{{ $website->deployment_slug }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('Database') }}</dt><dd class="mt-1 font-mono">{{ $website->databaseIdentifier() }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('Releases kept') }}</dt><dd class="mt-1">{{ $website->release_retention }}</dd></div>
        </dl>
    </x-signal.ui.card>

    <x-signal.ui.page-tabs :tabs="$tabs" :current="$tab" :url="route('infrastructure.websites.show', [$project, $website->id])" />

    <x-signal.ui.page-tab-panel name="overview" :current="$tab">
    @if ($log)
        <x-signal.ui.settings-section :title="__('Setup log')" :description="__('The last run of the setup script.')">
            <x-signal.ui.code-block class="m-4 max-h-96 overflow-auto whitespace-pre-wrap sm:m-6" :code="$log" />
        </x-signal.ui.settings-section>
    @endif

    <x-signal.ui.settings-section :title="__('Health')" :description="__('Health checks run in Monitoring, so failures open incidents and use its alert routing.')">
        <div class="p-4 text-sm sm:p-6">
            @if ($website->healthMonitor && $website->environment)
                @php($health = $website->healthMonitor->healthLabel())
                <p class="flex flex-wrap items-center gap-2">
                    <x-signal.ui.badge :tone="match ($health) { 'Up' => 'success', 'Down' => 'danger', 'Paused' => 'neutral', default => 'warning' }">{{ __($health) }}</x-signal.ui.badge>
                    <a href="{{ route('monitoring.monitors.show', [$website->environment->project_id, $website->healthMonitor->id]) }}" class="font-bold text-primary hover:underline">{{ __('Open the monitor') }}</a>
                    <span class="text-muted">https://{{ $website->url }}{{ $website->health_check_path }}</span>
                </p>
            @elseif (! $website->health_check_enabled)
                <p class="text-muted">{{ __('Health checks are off.') }}</p>
            @elseif (! $website->environment)
                <p class="text-muted">{{ __('Link the website to an environment to check its health.') }}</p>
            @else
                <p class="text-muted">{{ __('Turn on Monitoring for :project to check this website’s health.', ['project' => $website->environment->project->name]) }}</p>
            @endif
        </div>
    </x-signal.ui.settings-section>
    </x-signal.ui.page-tab-panel>

    <x-signal.ui.page-tab-panel name="domains" :current="$tab">
    <x-signal.ui.settings-section :title="__('Domains')" :description="__('Aliases serve the website too; redirects send visitors elsewhere. The primary domain changes with the website’s domain setting.')">
        <div class="grid gap-4 p-4 sm:p-6">
            <ul class="divide-y divide-line">
                @foreach ($website->domains->sortBy(fn ($domain) => [$domain->type === 'primary' ? 0 : 1, $domain->hostname]) as $domain)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="font-bold text-ink">{{ $domain->hostname }} <span class="text-xs font-normal text-muted">{{ __(ucfirst($domain->type)) }}@if ($domain->redirect_url) → {{ $domain->redirect_url }}@endif @if ($domain->is_temporary) · {{ __('temporary') }}@endif</span></p>
                            <p class="text-xs text-muted">{{ __('DNS :dns · certificate :ssl', ['dns' => __($domain->dns_status), 'ssl' => __($domain->ssl_status)]) }}@if ($domain->certificate_expires_at) · {{ __('expires :date', ['date' => $domain->certificate_expires_at->toFormattedDayDateString()]) }}@endif @if ($domain->dnsProvider) · {{ $domain->dnsProvider->name }}@endif</p>
                        </div>
                        @if ($canManage && $domain->type !== 'primary')
                            <div class="flex gap-1">
                                @if ($domain->dnsProvider)
                                    <form method="POST" action="{{ route('infrastructure.websites.domains.sync', [$project, $website->id, $domain->id]) }}">@csrf<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Update DNS') }}</x-signal.ui.button></form>
                                @endif
                                <form method="POST" action="{{ route('infrastructure.websites.domains.destroy', [$project, $website->id, $domain->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($canManage)
                <div><x-signal.ui.button :href="route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'domains', 'dialog' => 'add-domain'])" variant="secondary" data-modal-trigger="add-domain">{{ __('Add a domain') }}</x-signal.ui.button></div>
                <x-signal.overlays.modal id="add-domain" :title="__('Add a domain to :website', ['website' => $website->name])" :description="__('An alias serves the website; a redirect sends visitors elsewhere. A certificate is issued once DNS points here.')">
                <form method="POST" action="{{ route('infrastructure.websites.domains.store', [$project, $website->id]) }}" class="grid items-start gap-4 sm:grid-cols-2">
                    @csrf
                    <input type="hidden" name="_modal" value="add-domain">
                    <x-signal.ui.input-field name="hostname" :label="__('Hostname')" placeholder="www.example.com" maxlength="255" required />
                    <x-signal.ui.select-field name="type" :label="__('Type')">
                        <option value="alias">{{ __('Alias') }}</option>
                        <option value="redirect" @selected(old('type') === 'redirect')>{{ __('Redirect') }}</option>
                    </x-signal.ui.select-field>
                    <x-signal.ui.input-field name="redirect_url" :label="__('Redirect to (redirects)')" placeholder="https://example.com" maxlength="2048" />
                    <x-signal.ui.select-field name="dns_provider_id" :label="__('Manage DNS with')">
                        <option value="">{{ __('I’ll point DNS myself') }}</option>
                        @foreach ($dnsProviders as $dns)
                            <option value="{{ $dns->id }}" @selected((int) old('dns_provider_id') === $dns->id)>{{ $dns->name }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <div class="flex justify-end sm:col-span-2">
                        <x-signal.ui.button type="submit" variant="primary">{{ __('Add domain') }}</x-signal.ui.button>
                    </div>
                </form>
                </x-signal.overlays.modal>
                @if ($temporaryDomains && $dnsProviders->isNotEmpty())
                    <form method="POST" action="{{ route('infrastructure.websites.domains.temporary', [$project, $website->id]) }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <input type="hidden" name="dns_provider_id" value="{{ $dnsProviders->first()->id }}">
                        <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Get a temporary domain') }}</x-signal.ui.button>
                    </form>
                @endif
            @endif
        </div>
    </x-signal.ui.settings-section>
    </x-signal.ui.page-tab-panel>

    <x-signal.ui.page-tab-panel name="database" :current="$tab">
    <x-signal.ui.settings-section id="database" :title="__('Database')" :description="__('The MySQL database :database on the website’s server: its size and tables, extra logins, and copying it into another website.', ['database' => $website->databaseIdentifier()])">
        <div class="grid gap-5 p-4 sm:p-6">
            @if ($canManage && ! $canManageDatabase)
                <x-signal.ui.alert tone="info">{{ __('Database tools come with the Pro Deploy plan and above.') }}</x-signal.ui.alert>
            @endif

            <div class="grid gap-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-bold text-ink">{{ __('Inspection') }}</h3>
                    @if ($canManageDatabase)
                        <form method="POST" action="{{ route('infrastructure.websites.database.inspect', [$project, $website->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm" :disabled="$website->provisioning_status !== 'active'">{{ __('Inspect now') }}</x-signal.ui.button></form>
                    @endif
                </div>
                @if ($snapshot === null)
                    <p class="text-sm text-muted">{{ __('Not inspected yet. Live websites are inspected every day.') }}</p>
                @elseif ($snapshot->status === 'failed')
                    <p class="text-sm text-danger">{{ $snapshot->error }}</p>
                @elseif ($snapshot->status !== 'ready')
                    <p class="text-sm text-muted">{{ __('Inspecting…') }}</p>
                @else
                    <dl class="grid gap-4 text-sm sm:grid-cols-4">
                        <div><dt class="text-xs text-muted">{{ __('Size') }}</dt><dd class="mt-1 font-bold">{{ \Illuminate\Support\Number::fileSize($snapshot->size_bytes ?? 0, maxPrecision: 1) }}</dd></div>
                        <div><dt class="text-xs text-muted">{{ __('Tables') }}</dt><dd class="mt-1 font-bold">{{ count($snapshot->tables ?? []) }}</dd></div>
                        <div><dt class="text-xs text-muted">{{ __('Connections') }}</dt><dd class="mt-1 font-bold">{{ $snapshot->active_connections ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-muted">{{ __('Checked') }}</dt><dd class="mt-1">{{ $snapshot->collected_at?->diffForHumans() }}</dd></div>
                    </dl>
                    @if (($snapshot->tables ?? []) !== [])
                        <x-signal.ui.disclosure :title="__('Tables')">
                            <p class="font-mono text-xs break-words text-muted">{{ implode(', ', $snapshot->tables ?? []) }}</p>
                        </x-signal.ui.disclosure>
                    @endif
                @endif
            </div>

            <div class="grid gap-3 border-t border-line pt-5">
                <h3 class="font-bold text-ink">{{ __('Users') }}</h3>
                @if ($databaseUsers->isEmpty())
                    <p class="text-sm text-muted">{{ __('Only the website’s own user, :user.', ['user' => $website->databaseIdentifier()]) }}</p>
                @else
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($databaseUsers as $databaseUser)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                                <div class="min-w-0">
                                    <p><span class="font-mono font-bold">{{ $databaseUser->username }}</span> <span class="text-muted">· {{ __(\App\Models\DatabaseUser::PRIVILEGES[$databaseUser->privilege] ?? $databaseUser->privilege) }}@if ($databaseUser->expires_at) · {{ __('expires :when', ['when' => $databaseUser->expires_at->diffForHumans()]) }}@endif</span></p>
                                    @if ($databaseUser->status === 'failed')<p class="text-xs text-danger">{{ $databaseUser->error }}</p>@endif
                                </div>
                                <div class="flex items-center gap-2">
                                    <x-signal.ui.badge :tone="match ($databaseUser->status) { 'active' => 'success', 'failed' => 'danger', default => 'neutral' }">{{ match ($databaseUser->status) { 'active' => __('Active'), 'failed' => __('Failed'), 'removing' => __('Removing'), default => __('Adding') } }}</x-signal.ui.badge>
                                    @if ($canManage && $databaseUser->status !== 'removing')
                                        <form method="POST" action="{{ route('infrastructure.websites.database.users.destroy', [$project, $website->id, $databaseUser->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($canManageDatabase)
                    <div><x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'add-database-user'])" variant="secondary" size="sm" data-modal-trigger="add-database-user">{{ __('Add a database user') }}</x-signal.ui.button></div>
                    <x-signal.overlays.modal id="add-database-user" :title="__('Add a database user')" :description="__('For reporting tools and one-off access. The password is shown once; the user can expire by itself.')">
                        <form method="POST" action="{{ route('infrastructure.websites.database.users.store', [$project, $website->id]) }}" class="grid items-start gap-4 sm:grid-cols-3">
                            @csrf
                            <input type="hidden" name="_modal" value="add-database-user">
                            <x-signal.ui.input-field id="database-username" name="username" :label="__('Username')" placeholder="reporting" maxlength="32" required />
                            <x-signal.ui.select-field id="database-privilege" name="privilege" :label="__('Access')">
                                @foreach (\App\Models\DatabaseUser::PRIVILEGES as $key => $label)
                                    <option value="{{ $key }}" @selected(old('privilege') === $key)>{{ __($label) }}</option>
                                @endforeach
                            </x-signal.ui.select-field>
                            <x-signal.ui.select-field id="database-expiry" name="expires_in_days" :label="__('Expires')">
                                <option value="">{{ __('Never') }}</option>
                                @foreach ([1, 7, 30, 90] as $days)
                                    <option value="{{ $days }}" @selected((string) old('expires_in_days') === (string) $days)>{{ trans_choice('In :count day|In :count days', $days) }}</option>
                                @endforeach
                            </x-signal.ui.select-field>
                            <div class="flex justify-end sm:col-span-3"><x-signal.ui.button type="submit" variant="primary">{{ __('Add user') }}</x-signal.ui.button></div>
                        </form>
                    </x-signal.overlays.modal>
                @endif
            </div>

            @if ($canManageDatabase || $copies->isNotEmpty())
                <div class="grid gap-3 border-t border-line pt-5">
                    <h3 class="font-bold text-ink">{{ __('Copy into another website') }}</h3>
                    @foreach ($copies as $copy)
                        <p class="text-sm"><span class="text-muted">{{ $copy->created_at?->toDayDateTimeString() }}</span> · {{ $copy->source->name }} → {{ $copy->target->name }} · {{ match ($copy->status) { 'succeeded' => __('Done'), 'failed' => __('Failed'), 'running' => __('Running'), default => __('Queued') } }}@if ($copy->error) · <span class="text-danger">{{ $copy->error }}</span>@endif</p>
                    @endforeach
                    @if ($canManageDatabase && $copyTargets->isEmpty())
                        <p class="text-sm text-muted">{{ __('No other websites on this server.') }}</p>
                    @elseif ($canManageDatabase)
                        <form method="POST" action="{{ route('infrastructure.websites.database.copy', [$project, $website->id]) }}" class="grid items-start gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:grid-cols-2">
                            @csrf
                            <x-signal.ui.select-field id="copy-target" name="target_website_id" :label="__('Overwrite the database of')">
                                @foreach ($copyTargets as $target)
                                    <option value="{{ $target->id }}" @disabled($target->environment?->kind === \App\Enums\EnvironmentKind::Production)>{{ $target->name }}@if ($target->environment?->kind === \App\Enums\EnvironmentKind::Production) ({{ __('production') }})@endif</option>
                                @endforeach
                            </x-signal.ui.select-field>
                            <x-signal.ui.input-field id="copy-confirmation" name="confirmation" :label="__('Type its name to confirm')" autocomplete="off" maxlength="120" required />
                            <p class="text-xs text-muted sm:col-span-2">{{ __('Every table in the chosen website’s database is replaced with a copy of this one. This can’t be undone.') }}</p>
                            <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="danger">{{ __('Copy database') }}</x-signal.ui.button></div>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </x-signal.ui.settings-section>
    </x-signal.ui.page-tab-panel>

    <x-signal.ui.page-tab-panel name="backups" :current="$tab">
    <x-signal.ui.settings-section id="backups" :title="__('Backups')" :description="__('The database, .env file and shared storage, sent with restic to a backup destination. Times are UTC.')">
        <div class="grid gap-4 p-4 sm:p-6">
            @if ($canManage && ! $canBackUp)
                <x-signal.ui.alert tone="info">{{ __('Managed backups come with the Pro Deploy plan and above. Backups already taken can still be restored.') }}</x-signal.ui.alert>
            @elseif ($canBackUp && $backupDestinations->isEmpty())
                <p class="text-sm text-muted">{{ __('Add a backup destination first.') }} <a href="{{ route('infrastructure.backups', $project) }}" class="font-bold text-primary hover:underline">{{ __('Backup destinations') }}</a></p>
            @endif

            @if ($schedules->isNotEmpty())
                <ul class="divide-y divide-line text-sm">
                    @foreach ($schedules as $schedule)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                            <span>{{ $schedule->frequency === 'weekly' ? __('Every :day at :time', ['day' => \Carbon\CarbonImmutable::now()->startOfWeek(\Carbon\CarbonInterface::SUNDAY)->addDays((int) $schedule->weekday)->dayName, 'time' => $schedule->run_at]) : __('Every day at :time', ['time' => $schedule->run_at]) }} → {{ $schedule->destination->name }} · {{ trans_choice('keeps :count snapshot|keeps :count snapshots', $schedule->retention_count) }}</span>
                            @if ($canManage)
                                <form method="POST" action="{{ route('infrastructure.websites.backup-schedules.destroy', [$project, $website->id, $schedule->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($canBackUp && $backupDestinations->isNotEmpty())
                <div class="grid gap-4 lg:grid-cols-2">
                    <form method="POST" action="{{ route('infrastructure.websites.backups.store', [$project, $website->id]) }}" class="grid content-start items-start gap-4 rounded-panel border border-line bg-surface-muted p-4">
                        @csrf
                        <x-signal.ui.select-field id="backup-now-destination" name="backup_destination_id" :label="__('Back up now to')">
                            @foreach ($backupDestinations as $destination)
                                <option value="{{ $destination->id }}">{{ $destination->name }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <div><x-signal.ui.button type="submit" variant="secondary" :disabled="$website->provisioning_status !== 'active'">{{ __('Back up now') }}</x-signal.ui.button></div>
                    </form>
                    <div><x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'add-backup-schedule'])" variant="secondary" size="sm" data-modal-trigger="add-backup-schedule">{{ __('Schedule backups') }}</x-signal.ui.button></div>
                    <x-signal.overlays.modal id="add-backup-schedule" :title="__('Schedule backups')" :description="__('Back the database and files up on a cron schedule, keeping as many copies as you choose.')">
                        <form method="POST" action="{{ route('infrastructure.websites.backup-schedules.store', [$project, $website->id]) }}" class="grid items-start gap-4 sm:grid-cols-2">
                            @csrf
                            <input type="hidden" name="_modal" value="add-backup-schedule">
                            <x-signal.ui.select-field id="schedule-destination" name="backup_destination_id" :label="__('Schedule backups to')">
                                @foreach ($backupDestinations as $destination)
                                    <option value="{{ $destination->id }}">{{ $destination->name }}</option>
                                @endforeach
                            </x-signal.ui.select-field>
                            <x-signal.ui.select-field id="schedule-frequency" name="frequency" :label="__('How often')">
                                <option value="daily">{{ __('Every day') }}</option>
                                <option value="weekly" @selected(old('frequency') === 'weekly')>{{ __('Every week') }}</option>
                            </x-signal.ui.select-field>
                            <x-signal.ui.select-field id="schedule-weekday" name="weekday" :label="__('Day (weekly)')">
                                @foreach ([0, 1, 2, 3, 4, 5, 6] as $day)
                                    <option value="{{ $day }}" @selected((string) old('weekday', '0') === (string) $day)>{{ \Carbon\CarbonImmutable::now()->startOfWeek(\Carbon\CarbonInterface::SUNDAY)->addDays($day)->dayName }}</option>
                                @endforeach
                            </x-signal.ui.select-field>
                            <x-signal.ui.input-field id="schedule-time" name="run_at" type="time" :label="__('Time (UTC)')" value="02:00" required />
                            <x-signal.ui.input-field id="schedule-retention" name="retention_count" type="number" min="1" max="365" :label="__('Snapshots to keep')" value="14" required />
                            <div class="flex justify-end self-end"><x-signal.ui.button type="submit" variant="primary">{{ __('Save schedule') }}</x-signal.ui.button></div>
                        </form>
                    </x-signal.overlays.modal>
                </div>
            @endif

            {{-- Follows a backup, restore or check while one is running. --}}
            @php($backupsBusy = collect($backups)->contains(fn ($backup): bool => in_array($backup->status, ['queued', 'running'], true) || in_array($backup->restores->first()?->status, ['queued', 'running'], true) || in_array($backup->verifications->first()?->status, ['queued', 'running'], true)))
            <div id="backups-live" @if ($backupsBusy) data-live-region data-live-interval="5000" @endif>
                @if ($backups->isEmpty())
                    <p class="text-sm text-muted">{{ __('No backups yet.') }}</p>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($backups as $backup)
                            @php($restore = $backup->restores->first())
                            @php($verification = $backup->verifications->first())
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div class="min-w-0 text-sm">
                                    <p class="flex flex-wrap items-center gap-2"><span class="font-bold text-ink">{{ $backup->created_at?->toDayDateTimeString() }}</span> @include('infrastructure._backup-status', ['status' => $backup->status]) <span class="text-xs text-muted">{{ $backup->destination->name }}@if ($backup->size_bytes !== null) · {{ \Illuminate\Support\Number::fileSize($backup->size_bytes, maxPrecision: 1) }}@endif @if ($backup->website_backup_schedule_id) · {{ __('scheduled') }}@endif</span></p>
                                    @if ($backup->error)<p class="text-xs text-danger">{{ $backup->error }}</p>@endif
                                    @if ($restore)<p class="text-xs text-muted">{{ __('Restore:') }} {{ __($restore->status) }}@if ($restore->error) · <span class="text-danger">{{ $restore->error }}</span>@endif</p>@endif
                                    @if ($verification)<p class="text-xs text-muted">{{ __('Verification:') }} {{ __($verification->status) }}@if ($verification->error) · <span class="text-danger">{{ $verification->error }}</span>@endif</p>@endif
                                </div>
                                @if ($backup->isRestorable())
                                    <div class="flex gap-1">
                                        @if ($canBackUp)
                                            <form method="POST" action="{{ route('infrastructure.websites.backups.verify', [$project, $website->id, $backup->id]) }}">@csrf<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Verify') }}</x-signal.ui.button></form>
                                        @endif
                                        @if ($canManage)
                                            <x-signal.ui.button variant="quiet" size="sm" data-modal-trigger="restore-backup-{{ $backup->id }}">{{ __('Restore') }}</x-signal.ui.button>
                                            <x-signal.overlays.modal :id="'restore-backup-'.$backup->id" :title="__('Restore this backup?')" :description="__('The live database, .env file and shared storage are replaced with the backup from :date. The website is in maintenance mode meanwhile, and put back as it was if any step fails.', ['date' => $backup->created_at?->toDayDateTimeString()])">
                                                <form method="POST" action="{{ route('infrastructure.websites.backups.restore', [$project, $website->id, $backup->id]) }}" class="flex justify-end gap-3">
                                                    @csrf
                                                    <x-signal.ui.button type="button" variant="secondary" data-modal-close>{{ __('Cancel') }}</x-signal.ui.button>
                                                    <x-signal.ui.button type="submit" variant="danger">{{ __('Restore') }}</x-signal.ui.button>
                                                </form>
                                            </x-signal.overlays.modal>
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </x-signal.ui.settings-section>
    </x-signal.ui.page-tab-panel>

    @if ($canManage)
    <x-signal.ui.page-tab-panel name="settings" :current="$tab">
        <x-signal.ui.settings-section id="php-version" :title="__('PHP version')" :description="__('The website’s PHP-FPM version. Switching installs it on the server if needed (other websites keep theirs) and points this site at it; the next deploy reloads it too.')">
            <form method="POST" action="{{ route('infrastructure.websites.php-version', [$project, $website->id]) }}" class="flex flex-wrap items-end gap-3 p-4 sm:p-6">
                @csrf
                @method('PUT')
                <x-signal.ui.select-field name="php_version" :label="__('PHP')">
                    @foreach (\App\Models\Website::PHP_VERSIONS as $version)
                        <option value="{{ $version }}" @selected($website->phpVersion() === $version)>PHP {{ $version }}@if ($version === config('infrastructure.default_php_version')) ({{ __('default') }})@endif</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.button type="submit" variant="secondary" :disabled="$website->isProvisioning()">{{ __('Switch') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.settings-section>
        <x-signal.ui.settings-section id="web-server" :title="__('Web server (Caddy)')" :description="__('Add your own Caddy directives (headers, redirects, rewrites, basic auth…) inside this website’s site block. Caddy checks the whole configuration before using it, so a mistake never takes the site down.')">
            <div class="grid gap-4 p-4 sm:p-6">
                @if ($website->caddy_error)
                    <x-signal.ui.alert tone="danger" role="alert"><strong>{{ __('Caddy refused the last change; the previous configuration is still in use.') }}</strong> <span class="font-mono text-xs">{{ $website->caddy_error }}</span></x-signal.ui.alert>
                @endif
                <form method="POST" action="{{ route('infrastructure.websites.caddy', [$project, $website->id]) }}" class="grid gap-3">
                    @csrf
                    @method('PUT')
                    <x-signal.ui.textarea-field name="caddy_directives" :label="__('Your directives')" :value="$website->caddy_directives" rows="6" maxlength="5000" class="font-mono text-sm" placeholder="header X-Frame-Options DENY&#10;redir /old-page /new-page 301" />
                    <div><x-signal.ui.button type="submit" variant="secondary" :disabled="$website->isProvisioning()">{{ __('Save and apply') }}</x-signal.ui.button></div>
                </form>
                <details class="text-sm">
                    <summary class="cursor-pointer font-bold text-ink">{{ __('See the full configuration') }}</summary>
                    <x-signal.ui.code-block :code="app(\App\Services\Infrastructure\WebsiteCaddyConfiguration::class)->php($website, $website->deploymentPath('current').'/public')" class="mt-2 whitespace-pre-wrap" />
                </details>
            </div>
        </x-signal.ui.settings-section>
        <x-signal.ui.settings-section :title="__('Settings')" :description="__('A new server, domain or .env sets the website up again. Moving servers keeps the old copy until the new one is live.')">
            <form method="POST" action="{{ route('infrastructure.websites.update', [$project, $website->id]) }}" class="grid items-start gap-5 p-4 sm:grid-cols-2 sm:p-6">
                @csrf
                @method('PUT')
                @include('infrastructure._website-fields', ['website' => $website])
                <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="primary" :disabled="$website->isProvisioning()">{{ __('Save website') }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Delete this website')" :description="__('Its files, Caddy site and database are removed from the server. This can’t be undone.')">
            <div class="p-4 sm:p-6">
                <x-signal.ui.button variant="danger" data-modal-trigger="delete-website">{{ __('Delete website') }}</x-signal.ui.button>
                <x-signal.overlays.delete-confirmation id="delete-website" :route="route('infrastructure.websites.destroy', [$project, $website->id])" :title="__('Delete :website?', ['website' => $website->name])" :description="__('The files and database on the server are deleted too.')" :submit-label="__('Delete website')" />
            </div>
        </x-signal.ui.settings-section>
    </x-signal.ui.page-tab-panel>
    @endif
</x-signal.layouts.project>
