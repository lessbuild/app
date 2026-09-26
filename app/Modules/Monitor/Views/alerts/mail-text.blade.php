{{ ucfirst($payload['event']) }}: {{ $payload['title'] }}
{{ $payload['application'] }} / {{ $payload['environment'] }}
@if($payload['rule'])
Metric: {{ $payload['rule']['metric'] }} · Threshold: {{ $payload['rule']['threshold'] }}
@endif
@if($payload['monitor'] ?? null)
{{ strtoupper($payload['monitor']['type']) }} monitor: {{ $payload['monitor']['name'] }} · {{ \App\Modules\Monitor\Data\Telemetry\MonitorObservation::label($payload['observation']['reason'] ?? null) }}@if($payload['monitor']['type'] === 'http') · HTTP {{ $payload['observation']['http_status'] ?? '—' }}@endif

@if(($payload['observation']['details']['type'] ?? null) === 'queue')
Live workers: {{ $payload['observation']['details']['active_workers'] }} · ready jobs: {{ $payload['observation']['details']['metrics']['pending'] ?? 'unknown' }} · failed / dead-letter jobs: {{ $payload['observation']['details']['metrics']['failed'] ?? 'unknown' }}
@foreach($payload['observation']['details']['breaches'] as $breach)
{{ \App\Modules\Monitor\Data\Telemetry\MonitorObservation::label($breach) }}
@endforeach
@elseif(($payload['observation']['details']['type'] ?? null) === 'heartbeat')
Heartbeat deadline: {{ $payload['observation']['details']['deadline_at'] ?? 'not recorded' }} · observed {{ $payload['observation']['details']['received_at'] }}
@elseif(($payload['observation']['details']['type'] ?? null) === 'tls')
Certificate expires {{ $payload['observation']['details']['valid_until'] }} · {{ $payload['observation']['details']['days_remaining'] }} complete days remaining
@elseif(($payload['observation']['details']['type'] ?? null) === 'dns')
{{ $payload['observation']['details']['record_type'] }} · {{ $payload['observation']['details']['missing_count'] }} missing · {{ $payload['observation']['details']['unexpected_count'] }} additional records
@endif
@elseif($payload['observation'])
Observed value: {{ $payload['observation']['value'] ?? 'Unknown' }} · Samples: {{ $payload['observation']['samples'] ?? 0 }}
@endif
@if($payload['url'])
View incident: {{ $payload['url'] }}
@endif
Delivery ID: {{ $deliveryId }}
This is an automated monitoring notification. A test notification does not represent a real incident.
