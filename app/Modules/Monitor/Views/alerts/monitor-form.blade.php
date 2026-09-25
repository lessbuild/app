@extends('monitor::layouts.app')
@section('title', $monitor ? 'Edit monitor' : 'Create monitor')
@section('breadcrumb', 'Monitors')
@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <a href="{{ $monitor ? route('monitor.monitors.show', $monitor) : route('monitor.monitors.index') }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Monitors</a>
    <x-monitor::ui.page-header :title="$monitor ? 'Edit monitor' : 'Create monitor'" description="Monitor endpoints, domains and jobs you own or are authorised to check." />
    @if(!$monitor)
    <nav aria-label="Monitor type" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach(\App\Modules\Monitor\Http\Requests\SaveMonitorRequest::TYPES as $type => $label)
        <x-monitor::ui.button :href="route('monitor.monitors.create', ['check_type' => $type])" :variant="$checkType === $type ? 'soft' : 'secondary'" :aria-current="$checkType === $type ? 'page' : null">{{ $label }}</x-monitor::ui.button>
        @endforeach
    </nav>
    @endif
    @if(!request()->isSecure())<x-signal.ui.alert as="p" tone="warning" class="block p-4 text-sm text-warning dark:text-warning">This preview uses HTTP. Do not enter production credentials or secret URLs until HTTPS is configured.</x-signal.ui.alert>@endif
    @if($environmentOptions === [])
        <p>Create an application and environment first. <a href="{{ route('monitor.applications.index') }}" class="font-bold text-primary dark:text-primary">Manage applications →</a></p>
    @else
    <x-signal.ui.panel as="form" method="POST" action="{{ $monitor ? route('monitor.monitors.update', $monitor) : route('monitor.monitors.store') }}" class="space-y-6 p-6">
        @csrf
        <x-signal.ui.input type="hidden" name="check_type" value="{{ $checkType }}" :restore="false" />
        <p class="text-sm font-bold">{{ \App\Modules\Monitor\Http\Requests\SaveMonitorRequest::TYPES[$checkType] }}{{ $monitor ? ' · Type cannot be changed' : '' }}</p>
        @if($monitor) @method('PATCH') <x-signal.ui.input type="hidden" name="version" value="{{ $monitor->state_version }}" :restore="false" /> @endif
        <x-monitor::ui.input name="name" label="Monitor name (no secrets)" :value="$monitor?->name" maxlength="120" required />
        <x-monitor::ui.select name="environment_id" label="Environment" :value="$monitor?->environment_id" :options="$monitor ? [$monitor->environment_id => $environmentOptions[$monitor->environment_id]] : $environmentOptions" required />
        @if($checkType === 'queue')
        <x-monitor::ui.input name="queue_name" label="Queue label (no secrets)" :value="$monitor?->queue_name" maxlength="120" placeholder="emails / primary" required />
        <p class="text-xs leading-5 text-muted dark:text-subtle">Use one monitor per logical queue and environment. Your collector sends queue counts; each worker sends its own heartbeat. No broker credentials or job payloads are collected.</p>
        <div class="grid gap-5 sm:grid-cols-2">
            @foreach(\App\Modules\Monitor\Data\Telemetry\QueueMonitorSettings::LIMITS as $field => [$minimum, $maximum])
            <x-monitor::ui.input :name="'queue_settings['.$field.']'" :error-key="'queue_settings.'.$field" :id="'queue-settings-'.$field" :label="\App\Modules\Monitor\Data\Telemetry\QueueMonitorSettings::LABELS[$field]" type="number" :min="$minimum" :max="$maximum" :value="$monitor ? ($monitor->queue_settings[$field] ?? null) : \App\Modules\Monitor\Data\Telemetry\QueueMonitorSettings::DEFAULTS[$field]" :required="in_array($field, ['report_timeout_seconds', 'worker_timeout_seconds', 'minimum_workers'])" />
            @endforeach
        </div>
        <p class="text-xs leading-5 text-muted dark:text-subtle">Blank maximums disable those thresholds; zero means no jobs of that kind are allowed. Counts fail above their maximum. Failed jobs means the current failed / dead-letter backlog, not a cumulative error count or failure rate. Unknown metrics never count as zero or prove recovery.</p>
        <p class="text-xs leading-5 text-muted dark:text-subtle">Report and worker timeouts also provide startup grace. A missing collector report or too few fresh workers opens an incident after that grace. A busy job must keep sending heartbeats to be measured. One failed observation opens an incident; all enabled conditions must pass to recover. Deadlines are normally checked every minute.</p>
        @elseif($checkType === 'heartbeat')
        <x-monitor::ui.select name="heartbeat_schedule" label="Expected schedule" :value="$monitor?->heartbeat_schedule ?? 'interval'" :options="['interval' => 'Interval since the latest completed run', 'cron' => 'Fixed cron schedule']" />
        <div class="grid gap-5 sm:grid-cols-2">
            <x-monitor::ui.input name="heartbeat_interval_minutes" label="Interval (minutes, interval mode only)" type="number" min="1" max="43200" :value="$monitor?->heartbeat_interval_minutes ?? 60" />
            <x-monitor::ui.input name="heartbeat_grace_minutes" label="Grace / maximum run duration (minutes)" type="number" min="1" max="10080" :value="$monitor?->heartbeat_grace_minutes ?? 5" required />
            <x-monitor::ui.input name="heartbeat_cron" label="Five-field expression (cron mode only)" maxlength="100" :value="$monitor?->heartbeat_cron ?? '0 2 * * *'" />
            <x-monitor::ui.input name="heartbeat_timezone" label="IANA timezone (cron mode only)" maxlength="64" :value="$monitor?->heartbeat_timezone ?? 'UTC'" placeholder="Europe/London" />
        </div>
        <p class="text-xs leading-5 text-muted dark:text-subtle">Only fields for the selected schedule are used. Cron uses minute, hour, day of month, month and day of week. Laravel's cron parser can shift a nonexistent spring-forward time forward and repeat an autumn clock time. Match your job scheduler's timezone and DST behavior; use UTC or interval mode to avoid differences between cron engines.</p>
        <p class="text-xs leading-5 text-muted dark:text-subtle">Expect a completed run by the scheduled time plus grace. A start signal also sets a maximum run duration equal to grace. One failure or missed deadline opens an incident; a successful current run recovers it. Retries never extend deadlines. Deadline evaluation normally runs once per minute.</p>
        @elseif($checkType === 'http')
        <x-monitor::ui.input name="request_url" label="Full HTTP or HTTPS URL" type="password" autocomplete="off" maxlength="2048" :required="!$monitor" />
        <p class="text-xs leading-5 text-muted dark:text-subtle">{{ $monitor ? 'Stored target: '.$monitor->targetLabel().'. Leave blank to keep it. ' : '' }}URLs are encrypted and never included in check history. Redirects are not followed; enter the final endpoint.</p>
        <div class="grid gap-5 sm:grid-cols-2">
            <x-monitor::ui.select name="method" label="Method" :value="$monitor?->method ?? 'GET'" :options="['GET' => 'GET', 'HEAD' => 'HEAD']" />
            <x-monitor::ui.input name="status_min" label="Lowest accepted HTTP status" type="number" min="100" max="599" :value="$monitor?->status_min ?? 200" required />
            <x-monitor::ui.input name="status_max" label="Highest accepted HTTP status" type="number" min="100" max="599" :value="$monitor?->status_max ?? 299" required />
            <x-monitor::ui.input name="max_duration_ms" label="Optional maximum response time (ms)" type="number" min="1" max="20000" :value="$monitor?->max_duration_ms" />
        </div>
        <x-monitor::ui.input name="body_contains" label="Optional literal response text (GET only)" type="password" autocomplete="off" maxlength="500" />
        @if($monitor?->body_contains !== null)<x-monitor::ui.choice id="clear_body_contains" name="clear_body_contains" :checked="old('clear_body_contains', false)" label="Clear the stored response-text assertion" />@endif
        <x-monitor::ui.input name="bearer_token" label="Optional bearer token (HTTPS only)" type="password" autocomplete="off" maxlength="2048" />
        @if($monitor?->bearer_token !== null)<x-monitor::ui.choice id="clear_bearer_token" name="clear_bearer_token" :checked="old('clear_bearer_token', false)" label="Clear the stored bearer token" />@endif
        <p class="text-xs leading-5 text-muted dark:text-subtle">Blank secret fields keep their stored values. Changing the scheme, host or port clears the old bearer token unless you explicitly enter a replacement. Response bodies are checked in memory only, with a 512 KiB limit.</p>
        @else
        <x-monitor::ui.input name="hostname" label="Fully qualified hostname" autocomplete="off" maxlength="254" :required="!$monitor" placeholder="status.example.com" />
        <p class="text-xs leading-5 text-muted dark:text-subtle">{{ $monitor ? 'Stored target: '.$monitor->targetLabel().'. Leave blank to keep it. ' : '' }}Enter a hostname without a scheme, path or port. International names must use their ASCII / punycode form.</p>
        @if($checkType === 'dns')
        <div class="grid gap-5 sm:grid-cols-2">
            <x-monitor::ui.select name="dns_record_type" label="Record type" :value="$monitor?->dns_record_type ?? 'A'" :options="array_combine(\App\Modules\Monitor\Services\DnsRecordSet::TYPES, \App\Modules\Monitor\Services\DnsRecordSet::TYPES)" />
            <x-monitor::ui.select name="dns_match" label="Matching rule" :value="$monitor?->dns_match ?? 'contains'" :options="['contains' => 'Contains all expected records', 'exact' => 'Exactly matches the expected set']" />
        </div>
        <div class="space-y-2">
            <x-monitor::ui.textarea name="dns_expected" label="Expected records · one per line" rows="5" maxlength="16384" autocomplete="off" :required="!$monitor" sensitive aria-describedby="dns-expected-help" class="font-mono" />
            <p id="dns-expected-help" class="ui-help">1–20 records, up to 16 KiB total. A/AAAA: IP address. CNAME/NS: hostname. MX: priority and hostname, e.g. 10 mail.example.com (0 . for null MX). TXT: unquoted literal text; spaces and case matter. Blank lines are ignored. {{ $monitor ? count($monitor->dns_expected ?? []).' expectations stored. Leave blank to keep them; changing the record type requires replacements.' : '' }}</p>
        </div>
        <p class="text-xs leading-5 text-muted dark:text-subtle">Uses this checker's system DNS resolver and its cache. This is not authoritative, DNSSEC or global propagation verification. Raw record values are encrypted in history, visible to workspace members and omitted from notifications. Secret fields are not redisplayed after validation errors.</p>
        @elseif($checkType === 'tls')
        <div class="grid gap-5 sm:grid-cols-2">
            <x-monitor::ui.input name="tls_port" label="Direct TLS port" type="number" min="1" max="65535" :value="$monitor?->tls_port ?? 443" required />
            <x-monitor::ui.input name="tls_expiry_days" label="Fail when expiry is within (days)" type="number" min="1" max="90" :value="$monitor?->tls_expiry_days ?? 14" required />
        </div>
        <p class="text-xs leading-5 text-muted dark:text-subtle">Performs a TLS 1.2+ handshake only, with hostname and certificate-chain verification. No HTTP request or credentials are sent. Checks the leaf certificate, not intermediate expiry or revocation. STARTTLS is not supported. Failed verification may prevent expiry metadata from being read.</p>
        @else
        <x-monitor::ui.input name="tcp_port" label="TCP port" type="number" min="1" max="65535" :value="$monitor?->tcp_port ?? 5432" required />
        <p class="text-xs leading-5 text-muted dark:text-subtle">Resolves the hostname, verifies that every address is public, then opens a raw TCP connection to the selected port. No application payload is sent and no TLS or protocol negotiation is attempted.</p>
        @endif
        @endif
        @if(!in_array($checkType, ['heartbeat', 'queue']))
        <div class="grid gap-5 sm:grid-cols-2">
            <x-monitor::ui.select name="interval_minutes" label="Check frequency" :value="$monitor?->interval_minutes ?? 5" :options="\App\Modules\Monitor\Http\Requests\SaveMonitorRequest::INTERVALS" />
            <x-monitor::ui.input name="timeout_seconds" label="Check timeout (seconds)" type="number" min="1" max="20" :value="$monitor?->timeout_seconds ?? 10" required />
        </div>
        <div class="grid gap-5 sm:grid-cols-2">
            <x-monitor::ui.input name="trigger_checks" label="Consecutive failures to open an incident" type="number" min="1" max="10" :value="$monitor?->trigger_checks ?? 2" required />
            <x-monitor::ui.input name="recovery_checks" label="Consecutive passes to recover" type="number" min="1" max="10" :value="$monitor?->recovery_checks ?? 2" required />
        </div>
        @endif
        <x-monitor::ui.select name="enabled" label="Monitoring" :value="$monitor ? (int) $monitor->enabled : 1" :options="[1 => 'Enabled', 0 => 'Paused']" />
        <x-signal.ui.card as="fieldset" class="shadow-none space-y-3 p-4">
            <legend class="px-2 text-sm font-bold">Alert destinations (up to five)</legend>
            @php($selectedDestinations = old('environment_id') !== null ? (array) old('destinations', []) : $routes->pluck('id')->all())
            @forelse($destinations as $destination)
            <x-monitor::ui.choice :id="'monitor-destination-'.$destination->id" name="destinations[]" :value="$destination->id" :checked="in_array($destination->id, $selectedDestinations)" :label="$destination->name.($destination->enabled ? '' : ' (disabled)')" />
            @empty<p class="text-xs text-muted dark:text-subtle">No destinations yet. Incidents are still recorded in your inbox.</p>@endforelse
            <x-monitor::ui.select name="opened" label="Notify when an incident opens" :value="(int) ($routes->first()?->pivot->opened ?? true)" :options="[1 => 'Yes', 0 => 'No']" />
            <x-monitor::ui.select name="recovered" label="Notify when an incident recovers" :value="(int) ($routes->first()?->pivot->recovered ?? true)" :options="[1 => 'Yes', 0 => 'No']" />
            <p class="text-xs text-muted dark:text-subtle">Routing changes apply to future transitions; existing incidents are not backfilled.</p>
        </x-signal.ui.card>
        <p class="text-xs leading-5 text-muted dark:text-subtle">Pausing retains active incidents. Changing check conditions closes active incidents as “monitor changed”, never as recovered. Resuming a signal-based monitor starts fresh deadline windows and requires new run or worker IDs.</p>
        <x-monitor::ui.button>{{ $monitor ? 'Save monitor' : 'Create monitor' }}</x-monitor::ui.button>
    </x-signal.ui.panel>
    @endif
</div>
@endsection
