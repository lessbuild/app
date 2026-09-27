@php($project = $overview->project)
@php($secrets = session('secrets'))

<x-signal.layouts.project :overview="$overview" :title="$website->name" :description="$website->url.($website->server ? ' · '.$website->server->label() : '')">
    @foreach (['retry', 'domain', 'server_id', 'dns_provider_id'] as $key)
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
                <form method="POST" action="{{ route('infrastructure.websites.domains.store', [$project, $website->id]) }}" class="grid items-start gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:grid-cols-2">
                    @csrf
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
                    <div class="flex flex-wrap gap-3 sm:col-span-2">
                        <x-signal.ui.button type="submit" variant="secondary">{{ __('Add domain') }}</x-signal.ui.button>
                    </div>
                </form>
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

    @if ($canManage)
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
    @endif
</x-signal.layouts.project>
