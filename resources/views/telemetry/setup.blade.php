@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Connect your app')" :description="__('Each environment has its own ingest keys. Send events as JSON or through OpenTelemetry.')">
    @if ($issuedKey)
        <x-signal.ui.alert tone="success" role="status">
            <p class="font-bold">{{ __('Copy this ingest key now. It won’t be shown again.') }}</p>
            <x-signal.ui.code-block :code="$issuedKey['secret']" class="mt-2 break-all whitespace-pre-wrap" />
        </x-signal.ui.alert>
    @endif

    <x-signal.ui.card class="overflow-hidden">
        <ul class="divide-y divide-line" aria-label="{{ __('Environments') }}">
            @foreach ($health['environments'] as $item)
                @php($environment = $item['environment'])
                <li class="grid gap-3 px-5 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-extrabold text-ink">{{ $environment->name }}</p>
                            <p class="mt-0.5 text-xs text-muted">{{ $item['description'] }} · {{ trans_choice(':count event received|:count events received', $environment->telemetry_event_count, ['count' => number_format($environment->telemetry_event_count)]) }}</p>
                        </div>
                        <span class="flex items-center gap-2">
                            <x-signal.ui.badge :tone="$item['state']->tone()">{{ $item['state']->label() }}</x-signal.ui.badge>
                            <x-signal.ui.button :href="route('monitoring.ingest.deliveries', [$project, $environment->id])" variant="quiet" size="sm">{{ __('Deliveries') }}</x-signal.ui.button>
                        </span>
                    </div>
                    @foreach ($tokens->get($environment->id, collect()) as $token)
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-control bg-surface-muted px-3 py-2 text-sm">
                            <span><span class="font-semibold text-ink">{{ $token->name }}</span> <code class="text-xs text-muted">{{ $token->prefix }}…</code>
                                <span class="text-xs text-muted">· {{ $token->last_used_at ? __('used :time', ['time' => $token->last_used_at->diffForHumans()]) : __('never used') }}@if ($token->expires_at) · {{ __('expires :date', ['date' => $token->expires_at->toFormattedDateString()]) }}@endif</span></span>
                            @if ($canManage)
                                <span class="flex gap-2">
                                    <form method="POST" action="{{ route('monitoring.keys.rotate', [$project, $token->id]) }}">
                                        @csrf
                                        <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Replace') }}</x-signal.ui.button>
                                    </form>
                                    <form method="POST" action="{{ route('monitoring.keys.revoke', [$project, $token->id]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Revoke') }}</x-signal.ui.button>
                                    </form>
                                </span>
                            @endif
                        </div>
                    @endforeach
                    @if ($canManage)
                        <form method="POST" action="{{ route('monitoring.keys.store', [$project, $environment->id]) }}" class="flex flex-wrap items-end gap-2">
                            @csrf
                            <x-signal.ui.input-field name="name" :id="'key-name-'.$environment->id" :label="__('New key name')" value="{{ __('Collector') }}" maxlength="120" required :restore="false" />
                            <x-signal.ui.select-field name="expires_in_days" :id="'key-expiry-'.$environment->id" :label="__('Expires')">
                                <option value="">{{ __('Never') }}</option>
                                @foreach ([30, 90, 365] as $days)
                                    <option value="{{ $days }}">{{ trans_choice('In :count day|In :count days', $days, ['count' => $days]) }}</option>
                                @endforeach
                            </x-signal.ui.select-field>
                            <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Create key') }}</x-signal.ui.button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-signal.ui.card>

    <x-signal.ui.settings-section :title="__('Send events')" :description="__('Pick your stack for a working example. Replace the key placeholder with an ingest key from above.')">
        <div class="grid gap-4 p-4 sm:p-6">
            <form method="GET" action="{{ route('monitoring.setup', $project) }}" class="flex flex-wrap items-end gap-2">
                <x-signal.ui.select-field name="stack" :label="__('Stack')">
                    @foreach ($stackOptions as $value => $label)
                        <option value="{{ $value }}" @selected($stack === $value)>{{ $label }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Show') }}</x-signal.ui.button>
            </form>
            <p class="text-sm text-muted">{{ $guide['install'] }}</p>
            <p class="text-sm text-muted">{{ $guide['token'] }}</p>
            <x-signal.ui.code-block :code="$guide['code']" class="overflow-x-auto text-xs" />
            <p class="text-xs text-muted">{{ $guide['verification'] }}</p>
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('OpenTelemetry')" :description="__('Any OpenTelemetry SDK or collector can export traces, logs and metrics over OTLP/HTTP with JSON.')">
        <div class="p-4 sm:p-6">
            <x-signal.ui.code-block :code="$otlp" class="overflow-x-auto text-xs" />
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section id="trackers" :title="__('Ticket trackers')" :description="__('File tickets for issues in GitHub Issues, Linear or Jira, from each issue’s page. Credentials are your own and stored encrypted; give them only the access to create issues.')">
        <div class="grid gap-4 p-4 sm:p-6">
            @forelse ($trackers as $tracker)
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span><span class="font-bold text-ink">{{ $tracker->name }}</span> <span class="text-muted">· {{ \App\Models\IssueTracker::KINDS[$tracker->kind] ?? $tracker->kind }} · {{ $tracker->destination() }}</span></span>
                    @if ($canManage)
                        <form method="POST" action="{{ route('monitoring.trackers.destroy', [$project, $tracker->id]) }}">
                            @csrf @method('DELETE')
                            <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Disconnect') }}</x-signal.ui.button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="text-sm text-muted">{{ __('No trackers connected.') }}</p>
            @endforelse
            @if ($canManage)
                <form method="POST" action="{{ route('monitoring.trackers.store', $project) }}" class="grid gap-3 sm:grid-cols-2">
                    @csrf
                    <x-signal.ui.select-field name="kind" :label="__('Tracker')">
                        @foreach (\App\Models\IssueTracker::KINDS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <x-signal.ui.input-field name="name" :label="__('Name')" maxlength="80" placeholder="Engineering" />
                    <x-signal.ui.input-field name="repository" :label="__('GitHub: repository')" placeholder="acme/shop" maxlength="200" />
                    <x-signal.ui.input-field name="token" type="password" :label="__('GitHub or Jira: token')" :description="__('GitHub: a fine-grained token with Issues read and write. Jira: an API token.')" maxlength="500" autocomplete="off" />
                    <x-signal.ui.input-field name="api_key" type="password" :label="__('Linear: API key')" maxlength="500" autocomplete="off" />
                    <x-signal.ui.input-field name="team_id" :label="__('Linear: team ID')" maxlength="100" />
                    <x-signal.ui.input-field name="site" type="url" :label="__('Jira: site')" placeholder="https://acme.atlassian.net" maxlength="200" />
                    <x-signal.ui.input-field name="email" type="email" :label="__('Jira: email')" maxlength="200" />
                    <x-signal.ui.input-field name="project_key" :label="__('Jira: project key')" placeholder="OPS" maxlength="20" />
                    <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="secondary">{{ __('Connect') }}</x-signal.ui.button></div>
                </form>
            @endif
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section id="browser-errors" :title="__('Browser errors')" :description="__('Catch JavaScript errors and unhandled promise rejections in your visitors’ browsers. They become issues from the “browser” service, beside your server errors. The key in the snippet is public; errors are only accepted from the origins you list.')">
        <div class="grid gap-6 p-4 sm:p-6">
            @foreach ($health['environments'] as $item)
                @php($environment = $item['environment'])
                <div class="grid gap-3">
                    <p class="font-extrabold text-ink">{{ $environment->name }} <x-signal.ui.badge :tone="$environment->browser_key ? 'success' : 'neutral'">{{ $environment->browser_key ? __('On') : __('Off') }}</x-signal.ui.badge></p>
                    @if ($environment->browser_key)
                        @php($browserSnippet = '<script src="'.asset('monitoring/browser.js').'" data-key="'.$environment->browser_key.'" data-release="YOUR_RELEASE" defer></script>')
                        <x-signal.ui.code-block :code="$browserSnippet" class="whitespace-pre-wrap break-all text-xs" />
                        <p class="text-xs text-muted">{{ __('Put it in the <head> of every page. Set data-release to the version you deployed, so errors are tied to releases. Report caught errors with window.buildpusherError(error).') }}</p>
                    @endif
                    @if ($canManage)
                        <form method="POST" action="{{ route('monitoring.browser-errors', [$project, $environment->id]) }}" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                            @csrf @method('PUT')
                            <input type="hidden" name="enabled" value="1">
                            <x-signal.ui.input-field name="origins" :id="'browser-origins-'.$environment->id" :label="__('Origins (space or comma separated)')" :value="implode(' ', $environment->browser_origins ?? [])" maxlength="2000" placeholder="https://example.com https://www.example.com" :restore="false" />
                            <x-signal.ui.button type="submit" variant="secondary">{{ $environment->browser_key ? __('Save origins') : __('Turn on') }}</x-signal.ui.button>
                        </form>
                        @if ($environment->browser_key)
                            <form method="POST" action="{{ route('monitoring.browser-errors', [$project, $environment->id]) }}">
                                @csrf @method('PUT')
                                <input type="hidden" name="enabled" value="0">
                                <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Turn off') }}</x-signal.ui.button>
                            </form>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    </x-signal.ui.settings-section>
</x-signal.layouts.project>
