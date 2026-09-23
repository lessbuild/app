<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>{{ config('app.name') }} incident notification</title></head>
<body>
    <h1>{{ ucfirst($payload['event']) }}: {{ $payload['title'] }}</h1>
    <p>{{ $payload['application'] }} / {{ $payload['environment'] }}</p>
    @if($payload['rule'])<p>Metric: {{ $payload['rule']['metric'] }} · Threshold: {{ $payload['rule']['threshold'] }}</p>@endif
    @if($payload['monitor'] ?? null)
    <p>{{ strtoupper($payload['monitor']['type']) }} monitor: {{ $payload['monitor']['name'] }} · {{ \App\Modules\Monitor\Data\Telemetry\MonitorObservation::label($payload['observation']['reason'] ?? null) }}@if($payload['monitor']['type'] === 'http') · HTTP {{ $payload['observation']['http_status'] ?? '—' }}@endif</p>
    <x-monitor::ui.monitor-details :details="$payload['observation']['details'] ?? []" />
    @elseif($payload['observation'])<p>Observed value: {{ $payload['observation']['value'] ?? 'Unknown' }} · Samples: {{ $payload['observation']['samples'] ?? 0 }}</p>@endif
    @if($payload['url'])<p><a href="{{ $payload['url'] }}">View incident in {{ config('app.name') }}</a></p>@endif
    <p>Delivery ID: {{ $deliveryId }}</p>
    <p>This is an automated monitoring notification. A test notification does not represent a real incident.</p>
</body></html>
