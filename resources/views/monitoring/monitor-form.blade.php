@php($project = $overview->project)
@php($types = \App\Http\Requests\Monitoring\MonitorRequest::TYPES)
@php($settings = \App\Support\Monitoring\QueueMonitorSettings::class)
@php($routing = $routing ?? null)

<x-signal.layouts.project :overview="$overview" :title="$monitor ? __('Edit :monitor', ['monitor' => $monitor->name]) : __('Add a monitor')" :description="__('Only monitor endpoints, domains and jobs you own or are allowed to check.')">
    {{-- The part a modal shows when this page is opened from a list (x-signal.overlays.page-modal). --}}
    <div data-modal-content class="grid gap-6">
        @if (! $monitor)
            <nav aria-label="{{ __('Monitor type') }}" class="flex flex-wrap gap-2">
                @foreach ($types as $type => $label)
                    <x-signal.ui.button :href="route('monitoring.monitors.create', [$project, 'check_type' => $type])" :variant="$checkType === $type ? 'soft' : 'secondary'" size="sm" :aria-current="$checkType === $type ? 'page' : null">{{ __($label) }}</x-signal.ui.button>
                @endforeach
            </nav>
        @endif

        <x-signal.ui.card>
            <form method="POST" action="{{ $monitor ? route('monitoring.monitors.update', [$project, $monitor->id]) : route('monitoring.monitors.store', $project) }}" class="grid gap-6 p-4 sm:p-6">
                @csrf
                @if ($monitor)
                    @method('PUT')
                    <input type="hidden" name="version" value="{{ $monitor->state_version }}">
                @endif
                <input type="hidden" name="check_type" value="{{ $checkType }}">
                @error('check_type')
                    <x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>
                @enderror
                <p class="text-sm font-bold text-ink">{{ __($types[$checkType]) }}@if ($monitor) <span class="font-normal text-muted">· {{ __('the type can’t be changed') }}</span>@endif</p>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-signal.ui.input-field name="name" :label="__('Name')" :value="$monitor?->name" :description="__('Shown in alerts. Don’t put secrets in it.')" maxlength="120" required />
                    <x-signal.ui.select-field name="environment_id" :label="__('Environment')" :description="$monitor ? __('The environment can’t be changed.') : null" required>
                        @foreach ($overview->environments as $environment)
                            @if (! $monitor || $monitor->environment_id === $environment->id)
                                <option value="{{ $environment->id }}" @selected(old('environment_id', $monitor?->environment_id) === $environment->id)>{{ $environment->name }}</option>
                            @endif
                        @endforeach
                    </x-signal.ui.select-field>
                </div>

                @if ($checkType === 'queue')
                    <x-signal.ui.input-field name="queue_name" :label="__('Queue label')" :value="$monitor?->queue_name" :description="__('One monitor per queue and environment, such as “emails”. Your collector sends queue counts and each worker sends its own heartbeat; no credentials or job payloads are collected.')" maxlength="120" placeholder="emails" required />
                    <div class="grid gap-5 sm:grid-cols-2">
                        @foreach ($settings::LIMITS as $field => [$minimum, $maximum])
                            <x-signal.ui.input-field :name="'queue_settings['.$field.']'" :error-key="'queue_settings.'.$field" :id="'queue-settings-'.$field" :label="__($settings::LABELS[$field])" type="number" :min="$minimum" :max="$maximum"
                                :value="$monitor ? ($monitor->queue_settings[$field] ?? null) : $settings::DEFAULTS[$field]" :required="in_array($field, ['report_timeout_seconds', 'worker_timeout_seconds', 'minimum_workers'], true)" />
                        @endforeach
                    </div>
                    <p class="text-xs text-muted">{{ __('Leave a maximum blank to turn it off; zero allows none. One failed observation opens an incident, and every condition must pass to recover. The timeouts also give a new monitor time to receive its first signals.') }}</p>
                @elseif ($checkType === 'heartbeat')
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-signal.ui.select-field name="heartbeat_schedule" :label="__('Expected schedule')">
                            <option value="interval" @selected(old('heartbeat_schedule', $monitor?->heartbeat_schedule ?? 'interval') === 'interval')>{{ __('Every few minutes, after the last run') }}</option>
                            <option value="cron" @selected(old('heartbeat_schedule', $monitor?->heartbeat_schedule) === 'cron')>{{ __('A cron schedule') }}</option>
                        </x-signal.ui.select-field>
                        <x-signal.ui.input-field name="heartbeat_grace_minutes" :label="__('Grace and longest run (minutes)')" type="number" min="1" max="10080" :value="$monitor?->heartbeat_grace_minutes ?? 5" required />
                        <x-signal.ui.input-field name="heartbeat_interval_minutes" :label="__('Interval (minutes)')" :description="__('For the interval schedule.')" type="number" min="1" max="43200" :value="$monitor?->heartbeat_interval_minutes ?? 60" />
                        <x-signal.ui.input-field name="heartbeat_cron" :label="__('Cron expression')" :description="__('For the cron schedule: five fields.')" maxlength="100" :value="$monitor?->heartbeat_cron ?? '0 2 * * *'" />
                        <x-signal.ui.input-field name="heartbeat_timezone" :label="__('Cron time zone')" maxlength="64" :value="$monitor?->heartbeat_timezone ?? 'UTC'" placeholder="Europe/London" />
                    </div>
                    <p class="text-xs text-muted">{{ __('A completed run is expected by the scheduled time plus the grace period. A start signal also limits the run to the grace period. One failure or missed deadline opens an incident; the next successful run closes it.') }}</p>
                @elseif ($checkType === 'flow')
                    <x-signal.ui.textarea-field name="flow_steps" :label="__('Steps')" rows="12" maxlength="10000" :restore="false" :required="! $monitor" class="font-mono" :placeholder="\App\Support\Monitoring\FlowSteps::EXAMPLE"
                        :description="($monitor ? __('Stored encrypted; leave blank to keep the current :count steps.', ['count' => count(\App\Support\Monitoring\FlowSteps::parse((string) $monitor->flow_steps)['steps'])]).' ' : '').__('Up to 10 steps, separated by a blank line. Each starts with a method and URL; then header Name: value, form a=1&b=2 or json {…}, expect 200 “text” and extract name (regex). Cookies carry from step to step, and :placeholder inserts an extracted value. Use a dedicated test account.', ['placeholder' => '{'.'{name}'.'}'])" />
                @elseif ($checkType === 'http')
                    <x-signal.ui.input-field name="request_url" :label="__('URL')" type="password" autocomplete="off" maxlength="2048" :required="! $monitor" :restore="false"
                        :description="($monitor ? __('Now: :target. Leave blank to keep it.', ['target' => $monitor->targetLabel()]).' ' : '').__('Stored encrypted and never shown in check history. Redirects aren’t followed, so enter the final address.')" />
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-signal.ui.select-field name="method" :label="__('Method')">
                            @foreach (['GET', 'HEAD'] as $method)
                                <option value="{{ $method }}" @selected(old('method', $monitor?->method ?? 'GET') === $method)>{{ $method }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.input-field name="max_duration_ms" :label="__('Slowest acceptable response (ms)')" :description="__('Optional.')" type="number" min="1" max="20000" :value="$monitor?->max_duration_ms" />
                        <x-signal.ui.input-field name="status_min" :label="__('Lowest accepted status')" type="number" min="100" max="599" :value="$monitor?->status_min ?? 200" required />
                        <x-signal.ui.input-field name="status_max" :label="__('Highest accepted status')" type="number" min="100" max="599" :value="$monitor?->status_max ?? 299" required />
                    </div>
                    <x-signal.ui.input-field name="body_contains" :label="__('Response must contain (GET only)')" :description="__('Optional exact text, checked in memory (up to 512 KB).')" type="password" autocomplete="off" maxlength="500" :restore="false" />
                    @if ($monitor?->body_contains !== null)
                        <x-signal.ui.checkbox name="clear_body_contains" value="1">{{ __('Remove the stored text check') }}</x-signal.ui.checkbox>
                    @endif
                    <x-signal.ui.input-field name="bearer_token" :label="__('Bearer token (HTTPS only)')" :description="__('Optional. Changing the scheme, host or port removes the stored token unless you enter a new one.')" type="password" autocomplete="off" maxlength="2048" :restore="false" />
                    @if ($monitor?->bearer_token !== null)
                        <x-signal.ui.checkbox name="clear_bearer_token" value="1">{{ __('Remove the stored bearer token') }}</x-signal.ui.checkbox>
                    @endif
                @else
                    <x-signal.ui.input-field name="hostname" :label="__('Hostname')" autocomplete="off" maxlength="254" :required="! $monitor" placeholder="status.example.com"
                        :description="($monitor ? __('Now: :target. Leave blank to keep it.', ['target' => $monitor->targetLabel()]).' ' : '').__('No scheme, path or port. International names use their punycode form.')" />
                    @if ($checkType === 'dns')
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-signal.ui.select-field name="dns_record_type" :label="__('Record type')">
                                @foreach (\App\Services\Monitoring\DnsRecordSet::TYPES as $recordType)
                                    <option value="{{ $recordType }}" @selected(old('dns_record_type', $monitor?->dns_record_type ?? 'A') === $recordType)>{{ $recordType }}</option>
                                @endforeach
                            </x-signal.ui.select-field>
                            <x-signal.ui.select-field name="dns_match" :label="__('Match')">
                                <option value="contains" @selected(old('dns_match', $monitor?->dns_match ?? 'contains') === 'contains')>{{ __('Contains all expected records') }}</option>
                                <option value="exact" @selected(old('dns_match', $monitor?->dns_match) === 'exact')>{{ __('Exactly the expected records') }}</option>
                            </x-signal.ui.select-field>
                        </div>
                        <x-signal.ui.textarea-field name="dns_expected" :label="__('Expected records, one per line')" rows="4" :restore="false" :required="! $monitor" class="font-mono"
                            :description="__('Up to 20. A/AAAA: an IP address. CNAME/NS: a hostname. MX: priority and hostname, like 10 mail.example.com. TXT: the text without quotes.').($monitor ? ' '.trans_choice(':count record is stored; leave blank to keep it.|:count records are stored; leave blank to keep them.', count($monitor->dns_expected ?? []), ['count' => count($monitor->dns_expected ?? [])]) : '')" />
                        <p class="text-xs text-muted">{{ __('Uses this checker’s DNS resolver and its cache. It doesn’t verify DNSSEC or global propagation.') }}</p>
                    @elseif ($checkType === 'tls')
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-signal.ui.input-field name="tls_port" :label="__('Port')" type="number" min="1" max="65535" :value="$monitor?->tls_port ?? 443" required />
                            <x-signal.ui.input-field name="tls_expiry_days" :label="__('Warn this many days before expiry')" type="number" min="1" max="90" :value="$monitor?->tls_expiry_days ?? 14" required />
                        </div>
                        <p class="text-xs text-muted">{{ __('A TLS 1.2+ handshake with hostname and chain verification; no HTTP request is sent. It checks the leaf certificate only, and STARTTLS isn’t supported.') }}</p>
                    @else
                        <x-signal.ui.input-field name="tcp_port" :label="__('Port')" type="number" min="1" max="65535" :value="$monitor?->tcp_port ?? 5432" required />
                        <p class="text-xs text-muted">{{ __('Opens a TCP connection to every public address of the hostname. Nothing is sent over it.') }}</p>
                    @endif
                @endif

                @if (! in_array($checkType, ['heartbeat', 'queue'], true))
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-signal.ui.select-field name="interval_minutes" :label="__('Check every')">
                            @foreach (\App\Http\Requests\Monitoring\MonitorRequest::INTERVALS as $minutes => $label)
                                <option value="{{ $minutes }}" @selected((int) old('interval_minutes', $monitor?->interval_minutes ?? 5) === $minutes)>{{ __($label) }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.input-field name="timeout_seconds" :label="__('Timeout (seconds)')" type="number" min="1" max="20" :value="$monitor?->timeout_seconds ?? 10" required />
                        <x-signal.ui.input-field name="trigger_checks" :label="__('Failures in a row to open an incident')" type="number" min="1" max="10" :value="$monitor?->trigger_checks ?? 2" required />
                        <x-signal.ui.input-field name="recovery_checks" :label="__('Passes in a row to recover')" type="number" min="1" max="10" :value="$monitor?->recovery_checks ?? 2" required />
                    </div>
                @endif

                <x-signal.ui.checkbox name="enabled" value="1" unchecked-value="0" :checked="$monitor?->enabled ?? true" :description="__('A paused monitor keeps its open incident. Changing what it checks closes that incident as “monitor changed”, never as recovered.')">{{ __('Monitor is on') }}</x-signal.ui.checkbox>

                <fieldset class="grid gap-3 rounded-panel border border-line p-4">
                    <legend class="px-1 text-sm font-bold text-ink">{{ __('Alert destinations (up to five)') }}</legend>
                    @php($selected = old('environment_id') !== null ? array_map('intval', (array) old('destinations', [])) : $selectedDestinations)
                    @forelse ($destinations as $destination)
                        <x-signal.ui.checkbox :id="'destination-'.$destination->id" name="destinations[]" :value="$destination->id" :checked="in_array($destination->id, $selected, true)" :restore="false" error-key="destinations">{{ $destination->name }} <span class="font-normal text-muted">· {{ $destination->type->label() }}{{ $destination->enabled ? '' : ' · '.__('off') }}</span></x-signal.ui.checkbox>
                    @empty
                        <p class="text-sm text-muted">{{ __('No alert destinations yet. Incidents still show up under Incidents.') }} <a class="font-bold text-primary hover:underline" href="{{ route('monitoring.destinations', $project) }}">{{ __('Add a destination') }}</a></p>
                    @endforelse
                    <x-signal.ui.checkbox name="opened" value="1" unchecked-value="0" :checked="(bool) ($routing?->opened ?? true)">{{ __('Notify when an incident opens') }}</x-signal.ui.checkbox>
                    <x-signal.ui.checkbox name="recovered" value="1" unchecked-value="0" :checked="(bool) ($routing?->recovered ?? true)">{{ __('Notify when it recovers') }}</x-signal.ui.checkbox>
                </fieldset>

                <div class="flex flex-wrap gap-3">
                    <x-signal.ui.button type="submit" variant="primary">{{ $monitor ? __('Save monitor') : __('Add monitor') }}</x-signal.ui.button>
                    <x-signal.ui.button :href="$monitor ? route('monitoring.monitors.show', [$project, $monitor->id]) : route('monitoring.monitors', $project)" variant="quiet">{{ __('Cancel') }}</x-signal.ui.button>
                </div>
            </form>
        </x-signal.ui.card>
    </div>
</x-signal.layouts.project>
