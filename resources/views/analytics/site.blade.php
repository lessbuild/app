@php($project = $overview->project)
@php($snippet = '<script defer data-site="'.$site->public_id.'" src="'.url('/tracker/v1.js').'"></script>')

<x-signal.layouts.project :overview="$overview" :title="$site->name" :description="implode(', ', $site->domains)">
    @foreach (['domains', 'environment_id'] as $field)
        @error($field)
            <x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>
        @enderror
    @endforeach

    <x-signal.ui.settings-section :title="__('Tracking snippet')" :description="__('Paste it into the <head> of every page. It sets no cookies and respects an optional consent callback (data-consent).')">
        <div class="grid gap-4 p-4 sm:p-6">
            <x-signal.ui.code-block :code="$snippet" class="whitespace-pre-wrap break-all" />
            <p class="text-xs text-muted">{{ __('Custom events: window.buildpusher.track(\'signup\'). Add revenue to measure what goals and campaigns earn: window.buildpusher.track(\'purchase\', {revenue: 49.99, currency: \'EUR\'}).') }}</p>
            <div class="grid gap-2 text-sm">
                <p class="font-bold text-ink">{{ __('Optional: count more automatically') }}</p>
                <ul class="grid gap-1.5 text-muted">
                    <li><code class="font-mono text-ink">data-outbound</code> · {{ __('clicks on links to other sites') }}</li>
                    <li><code class="font-mono text-ink">data-downloads</code> · {{ __('file downloads (PDFs, zips, documents and more), or list your own: data-downloads="pdf,zip"') }}</li>
                    <li><code class="font-mono text-ink">data-vitals</code> · {{ __('page speed (Core Web Vitals) as real visitors experience it') }}</li>
                    <li><code class="font-mono text-ink">data-not-found</code> · {{ __('on your 404 page only, to see which missing pages people reach') }}</li>
                </ul>
                <x-signal.ui.code-block :code="str_replace(' src=', ' data-outbound data-downloads data-vitals src=', $snippet)" class="whitespace-pre-wrap break-all" />
                <p class="text-muted">{{ __('To leave your own visits out, open any page of the site once with ?bp_ignore=1 in each browser you use (?bp_ignore=0 counts it again).') }}</p>
            </div>
        </div>
    </x-signal.ui.settings-section>

    @php($origin = rtrim(url('/'), '/'))
    @php($originHost = parse_url($origin, PHP_URL_HOST))
    @php($proxySnippet = '<script defer data-site="'.$site->public_id.'" data-api="/bp/event" src="/bp/js/v1.js"></script>')
    @php($caddyProxy = "handle_path /bp/js/* {\n    rewrite * /tracker{path}\n    reverse_proxy {$origin} {\n        header_up Host {$originHost}\n    }\n}\nhandle_path /bp/event/* {\n    rewrite * /api/v1/collect{path}\n    reverse_proxy {$origin} {\n        header_up Host {$originHost}\n    }\n}")
    @php($nginxProxy = "location = /bp/js/v1.js {\n    proxy_pass {$origin}/tracker/v1.js;\n    proxy_set_header Host {$originHost};\n    proxy_ssl_server_name on;\n}\nlocation /bp/event/ {\n    proxy_pass {$origin}/api/v1/collect/;\n    proxy_set_header Host {$originHost};\n    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n    proxy_ssl_server_name on;\n}")
    <x-signal.ui.settings-section :title="__('Send through your own domain')" :description="__('Optional. Serve the script and send events from the site’s own domain, so ad blockers that stop third-party analytics don’t stop it. Add the proxy rules to your web server, then use this snippet instead.')">
        <div class="grid gap-4 p-4 sm:p-6">
            <x-signal.ui.code-block :code="$proxySnippet" class="whitespace-pre-wrap break-all" />
            <p class="text-sm font-bold text-ink">{{ __('Caddy (for a BuildPusher website, paste it into the website’s Caddy directives)') }}</p>
            <x-signal.ui.code-block :code="$caddyProxy" class="whitespace-pre-wrap break-all" />
            <p class="text-sm font-bold text-ink">{{ __('Nginx') }}</p>
            <x-signal.ui.code-block :code="$nginxProxy" class="whitespace-pre-wrap break-all" />
            <p class="text-xs text-muted">{{ __('The proxy must pass the visitor’s address in X-Forwarded-For (Caddy does this itself), or every visitor looks like your server.') }}</p>
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('Verification')" :description="__('Data is only accepted once one of the site’s hostnames is a verified domain of :project.', ['project' => $project->name])">
        <div class="flex flex-wrap items-center justify-between gap-3 p-4 sm:p-6">
            <x-signal.ui.badge :tone="$site->isVerified() ? 'success' : 'warning'">{{ $site->isVerified() ? __('Verified :time', ['time' => $site->verified_at?->diffForHumans()]) : __('Not verified') }}</x-signal.ui.badge>
            @if (! $site->isVerified() && $canManage)
                <div class="flex flex-wrap gap-2">
                    <x-signal.ui.button :href="route('projects.domains', $project)" variant="quiet" size="sm">{{ __('Project domains') }}</x-signal.ui.button>
                    <form method="POST" action="{{ route('analytics.sites.verify', [$project, $site->id]) }}">
                        @csrf
                        <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Check again') }}</x-signal.ui.button>
                    </form>
                </div>
            @endif
        </div>
    </x-signal.ui.settings-section>

    @if ($canManage)
        <x-signal.ui.settings-section :title="__('Google Search Console')" :description="__('See which Google searches bring people to the site, next to its visits. Read-only access; Google’s figures lag by about two days.')">
            <div class="grid gap-4 p-4 sm:p-6">
                @error('search_console')<x-signal.ui.alert tone="danger">{{ $message }}</x-signal.ui.alert>@enderror
                @if (! $searchConsole['configured'] && ! $site->search_console_token)
                    <p class="text-sm text-muted">{{ __('Search Console isn’t set up on this platform yet: it needs a Google OAuth client (GOOGLE_SEARCH_CONSOLE_CLIENT_ID and GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET).') }}</p>
                @elseif (! $site->search_console_token)
                    <form method="POST" action="{{ route('analytics.sites.search-console.connect', [$project, $site->id]) }}">
                        @csrf
                        <x-signal.ui.button type="submit" variant="primary">{{ __('Connect Google Search Console') }}</x-signal.ui.button>
                    </form>
                @else
                    @if ($searchConsole['error'])<x-signal.ui.alert tone="warning">{{ $searchConsole['error'] }}</x-signal.ui.alert>@endif
                    <form method="POST" action="{{ route('analytics.sites.search-console.property', [$project, $site->id]) }}" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                        @csrf @method('PUT')
                        <x-signal.ui.select-field name="search_console_property" :label="__('Property')" :description="$site->search_console_property ? null : __('Choose the property for this site.')">
                            @if (! $site->search_console_property)<option value="">{{ __('Choose…') }}</option>@endif
                            @foreach ($searchConsole['properties'] as $property)
                                <option value="{{ $property }}" @selected($site->search_console_property === $property)>{{ $property }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.button type="submit" variant="secondary">{{ __('Use this property') }}</x-signal.ui.button>
                    </form>
                    <form method="POST" action="{{ route('analytics.sites.search-console.disconnect', [$project, $site->id]) }}">
                        @csrf @method('DELETE')
                        <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Disconnect') }}</x-signal.ui.button>
                    </form>
                @endif
            </div>
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Raw data export')" :description="__('Each night, write the previous day’s raw events to a storage bucket as gzipped JSON lines, one file per day, laid out for BigQuery, Athena or your own tools. Use a Google Cloud Storage bucket to load into BigQuery directly.')">
            <div class="grid gap-4 p-4 sm:p-6">
                @if ($site->export_error)<x-signal.ui.alert tone="danger">{{ $site->export_error }}</x-signal.ui.alert>@endif
                @if ($site->export_bucket_id)
                    <p class="text-sm text-muted">{{ $site->exported_until ? __('Exported up to :date.', ['date' => $site->exported_until->toDateString()]) : __('The first file is written tonight.') }} <code class="font-mono text-ink">{{ ($site->export_prefix ? $site->export_prefix.'/' : '').'site='.$site->public_id.'/dt=YYYY-MM-DD/events.ndjson.gz' }}</code></p>
                @endif
                @if ($buckets->isEmpty())
                    <p class="text-sm text-muted">{{ __('Add a storage bucket to this project first.') }} <a class="ui-link" href="{{ route('infrastructure.storage', $project) }}">{{ __('Storage') }}</a></p>
                @else
                    <form method="POST" action="{{ route('analytics.sites.raw-export', [$project, $site->id]) }}" class="grid gap-3 sm:grid-cols-3 sm:items-end">
                        @csrf @method('PUT')
                        <x-signal.ui.select-field name="export_bucket_id" :label="__('Bucket')">
                            <option value="">{{ __('Don’t export') }}</option>
                            @foreach ($buckets as $bucket)
                                <option value="{{ $bucket->id }}" @selected($site->export_bucket_id === $bucket->id)>{{ $bucket->name }} ({{ $bucket->bucket }})</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.input-field name="export_prefix" :label="__('Folder (optional)')" :value="$site->export_prefix" placeholder="analytics" maxlength="200" />
                        <div><x-signal.ui.button type="submit" variant="secondary">{{ __('Save') }}</x-signal.ui.button></div>
                    </form>
                @endif
            </div>
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Import from Google Analytics')" :description="__('Bring a GA4 property’s daily history (pages, sources, channels, countries, cities, devices, browsers and campaigns) into this site’s reports. Only days before this site’s own data are imported, so nothing is counted twice.')">
            <div class="grid gap-4 p-4 sm:p-6">
                @error('property')<x-signal.ui.alert tone="danger">{{ $message }}</x-signal.ui.alert>@enderror
                @foreach ($googleAnalytics['imports']->where('status', '!=', 'connected') as $import)
                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <span>
                            <span class="font-bold text-ink">{{ $import->property_name ?? $import->property }}</span>
                            <span class="text-muted">· {{ $import->from_date?->toDateString() }} – {{ $import->until_date?->toDateString() }} · {{ match ($import->status) { 'queued' => __('Waiting'), 'running' => __('Importing'), 'failed' => __('Failed'), default => trans_choice(':count day imported|:count days imported', $import->days_imported, ['count' => number_format($import->days_imported)]) } }}</span>
                            @if ($import->error)<span class="block text-xs text-danger">{{ $import->error }}</span>@endif
                        </span>
                        <form method="POST" action="{{ route('analytics.sites.imports.destroy', [$project, $site->id, $import->id]) }}">
                            @csrf @method('DELETE')
                            <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove import') }}</x-signal.ui.button>
                        </form>
                    </div>
                @endforeach
                @if (! $googleAnalytics['configured'])
                    <p class="text-sm text-muted">{{ __('Google sign-in isn’t set up on this platform yet.') }}</p>
                @elseif ($googleAnalytics['connected'])
                    @if ($googleAnalytics['error'])
                        <x-signal.ui.alert tone="warning">{{ $googleAnalytics['error'] }}</x-signal.ui.alert>
                    @endif
                    <form method="POST" action="{{ route('analytics.sites.imports.start', [$project, $site->id, $googleAnalytics['connected']->id]) }}" class="grid gap-3 sm:grid-cols-3 sm:items-end">
                        @csrf
                        <x-signal.ui.select-field name="property" :label="__('Property')" required>
                            @foreach ($googleAnalytics['properties'] as $property)
                                <option value="{{ $property['id'] }}">{{ $property['name'] }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.input-field name="from" type="date" :label="__('From')" :value="now($site->timezone)->subYear()->toDateString()" required />
                        <x-signal.ui.input-field name="until" type="date" :label="__('To')" :value="now($site->timezone)->subDay()->toDateString()" required />
                        <div class="sm:col-span-3"><x-signal.ui.button type="submit" variant="primary">{{ __('Import') }}</x-signal.ui.button></div>
                    </form>
                @else
                    <form method="POST" action="{{ route('analytics.sites.imports.connect', [$project, $site->id]) }}">
                        @csrf
                        <x-signal.ui.button type="submit" variant="secondary">{{ __('Connect Google Analytics') }}</x-signal.ui.button>
                    </form>
                @endif
            </div>
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Reports and alerts')" :description="__('Email or Slack a summary every week (Mondays) or month (the 1st), from 8am in the site’s time zone, or an alert when a lot of people are on the site at once.')">
            <div class="grid gap-4 p-4 sm:p-6">
                @forelse ($site->notifications()->orderBy('id')->get() as $notification)
                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <span>
                            <span class="font-bold text-ink">{{ __(\App\Models\AnalyticsNotification::KINDS[$notification->kind] ?? $notification->kind) }}</span>
                            <span class="text-muted">· {{ $notification->destination() }}@if ($notification->threshold) · {{ trans_choice('at :count visitor|at :count visitors', $notification->threshold, ['count' => number_format($notification->threshold)]) }}@endif @if ($notification->last_sent_at) · {{ __('last sent :time', ['time' => $notification->last_sent_at->diffForHumans()]) }}@endif</span>
                            @if ($notification->last_error)<span class="block text-xs text-danger">{{ $notification->last_error }}</span>@endif
                        </span>
                        <form method="POST" action="{{ route('analytics.sites.notifications.destroy', [$project, $site->id, $notification->id]) }}">
                            @csrf @method('DELETE')
                            <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button>
                        </form>
                    </div>
                @empty
                    <p class="text-sm text-muted">{{ __('No reports or alerts yet.') }}</p>
                @endforelse
                <form method="POST" action="{{ route('analytics.sites.notifications.store', [$project, $site->id]) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                    @csrf
                    <x-signal.ui.select-field name="kind" :label="__('What')">
                        @foreach (\App\Models\AnalyticsNotification::KINDS as $value => $label)
                            <option value="{{ $value }}" @selected(old('kind') === $value)>{{ __($label) }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <x-signal.ui.select-field name="channel" :label="__('How')">
                        @foreach (\App\Models\AnalyticsNotification::CHANNELS as $value => $label)
                            <option value="{{ $value }}" @selected(old('channel') === $value)>{{ __($label) }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <x-signal.ui.input-field name="target" :label="__('Email or Slack webhook address')" maxlength="500" required />
                    <x-signal.ui.input-field name="threshold" type="number" min="1" :label="__('Spike at (current visitors)')" :description="__('For spike alerts only.')" />
                    <div class="sm:col-span-2 lg:col-span-4"><x-signal.ui.button type="submit" variant="secondary">{{ __('Add') }}</x-signal.ui.button></div>
                </form>
            </div>
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Share the report')" :description="__('Give clients or your team a read-only link to this site’s report. They don’t need an account. Releases and goal settings aren’t shown.')">
            <div class="grid gap-4 p-4 sm:p-6">
                @if ($site->share_token)
                    <x-signal.ui.code-block :code="route('analytics.shared', $site->share_token)" class="whitespace-pre-wrap break-all" />
                    <p class="text-sm text-muted">{{ $site->share_password ? __('Protected by a password. Shared :time.', ['time' => $site->shared_at?->diffForHumans()]) : __('Anyone with the link can see it. Shared :time.', ['time' => $site->shared_at?->diffForHumans()]) }}</p>
                    @unless ($site->share_password)
                        <p class="text-sm font-bold text-ink">{{ __('Embed it in another page') }}</p>
    @php($embedCode = '<iframe src="'.route('analytics.shared.embed', $site->share_token).'" style="width: 100%; height: 1600px; border: 0" loading="lazy" title="'.e($site->name).' analytics"></iframe>')
                        <x-signal.ui.code-block :code="$embedCode" class="whitespace-pre-wrap break-all" />
                    @endunless
                @endif
                <form method="POST" action="{{ route('analytics.sites.share', [$project, $site->id]) }}" class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                    @csrf
                    <x-signal.ui.input-field name="share_password" type="password" :label="__('Password (optional)')" :description="__('At least 8 characters. Leave empty for no password.')" autocomplete="new-password" />
                    <div class="flex flex-wrap items-center gap-3">
                        @if ($site->share_token)
                            <x-signal.ui.checkbox name="new_link" :show-errors="false">{{ __('New link (the old one stops working)') }}</x-signal.ui.checkbox>
                        @endif
                        <x-signal.ui.button type="submit" variant="primary">{{ $site->share_token ? __('Update sharing') : __('Create link') }}</x-signal.ui.button>
                    </div>
                </form>
                @if ($site->share_token)
                    <form method="POST" action="{{ route('analytics.sites.unshare', [$project, $site->id]) }}">
                        @csrf @method('DELETE')
                        <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Stop sharing') }}</x-signal.ui.button>
                    </form>
                @endif
            </div>
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Settings')" :description="__('Changing hostnames takes effect for the next visit.')">
            <form method="POST" action="{{ route('analytics.sites.update', [$project, $site->id]) }}" class="grid gap-5 p-4 sm:p-6">
                @csrf
                @method('PUT')
                @include('analytics._site-fields', ['site' => $site])
                <div><x-signal.ui.button type="submit" variant="primary">{{ __('Save') }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Delete this site')" :description="__('Deletes its visits, goals and reports. The snippet stops being accepted immediately.')">
            <div class="p-4 sm:p-6">
                <x-signal.ui.button variant="danger" data-modal-trigger="delete-site">{{ __('Delete :site', ['site' => $site->name]) }}</x-signal.ui.button>
                <x-signal.overlays.delete-confirmation id="delete-site" :route="route('analytics.sites.destroy', [$project, $site->id])" :title="__('Delete :site?', ['site' => $site->name])" :description="__('All of its analytics data is deleted.')" :submit-label="__('Delete site')" />
            </div>
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.project>
