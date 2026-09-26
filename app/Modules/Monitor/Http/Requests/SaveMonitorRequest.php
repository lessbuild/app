<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Data\Telemetry\QueueMonitorSettings;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\DnsRecordSet;
use App\Modules\Monitor\Services\HeartbeatSchedule;
use App\Modules\Monitor\Services\PublicHttpTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveMonitorRequest extends FormRequest
{
    public const TYPES = ['http' => 'HTTP uptime', 'dns' => 'DNS records', 'tls' => 'TLS certificate', 'tcp' => 'TCP port', 'heartbeat' => 'Cron / heartbeat', 'queue' => 'Queue / workers'];

    public const INTERVALS = [1 => 'Every minute', 5 => 'Every 5 minutes', 15 => 'Every 15 minutes', 30 => 'Every 30 minutes', 60 => 'Every hour'];

    public function authorize(CurrentWorkspace $workspace): bool
    {
        if ($monitor = $this->route('monitor')) {
            abort_unless($monitor instanceof Monitor && Monitor::forWorkspace($workspace->get())->visibleTo($this->user(), $workspace->get())->whereKey($monitor->id)->exists(), 404);
            Gate::authorize('update', $monitor);
        } else {
            Gate::authorize('create', [Monitor::class, $workspace->get()]);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    /** @return array<string, array<mixed>> */
    public function rules(CurrentWorkspace $workspace): array
    {
        $environments = Environment::forWorkspace($workspace->get())->visibleTo($this->user(), $workspace->get())->select('id');
        $type = $this->checkType();
        $http = $type === 'http';
        $dns = $type === 'dns';
        $tls = $type === 'tls';
        $tcp = $type === 'tcp';
        $heartbeat = $type === 'heartbeat';
        $queue = $type === 'queue';
        $signals = $heartbeat || $queue;
        $cron = ($this->validationData()['heartbeat_schedule'] ?? null) === 'cron';
        $monitor = $this->route('monitor');

        $rules = [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'check_type' => ['sometimes', 'string', Rule::in(array_keys(self::TYPES))],
            'environment_id' => ['required', 'integer', Rule::exists('monitor.environments', 'id')->where(fn (Builder $query): Builder => $query->whereIn('id', $environments))],
            'request_url' => [Rule::excludeIf(! $http), $monitor ? 'nullable' : 'required', 'string', 'max:2048'],
            'bearer_token' => [Rule::excludeIf(! $http), 'nullable', 'string', 'max:2048', 'not_regex:/[\x00-\x20\x7F]/'],
            'body_contains' => [Rule::excludeIf(! $http), 'nullable', 'string', 'max:500'],
            'clear_bearer_token' => [Rule::excludeIf(! $http), 'sometimes', 'boolean'],
            'clear_body_contains' => [Rule::excludeIf(! $http), 'sometimes', 'boolean'],
            'method' => [Rule::excludeIf(! $http), 'required', 'string', Rule::in(['GET', 'HEAD'])],
            'status_min' => [Rule::excludeIf(! $http), 'required', 'integer', 'between:100,599'],
            'status_max' => [Rule::excludeIf(! $http), 'required', 'integer', 'between:100,599', 'gte:status_min'],
            'max_duration_ms' => [Rule::excludeIf(! $http), 'nullable', 'integer', 'between:1,20000'],
            'hostname' => [Rule::excludeIf(! $dns && ! $tls && ! $tcp), $monitor ? 'nullable' : 'required', 'string', 'max:254'],
            'dns_record_type' => [Rule::excludeIf(! $dns), 'required', 'string', Rule::in(DnsRecordSet::TYPES)],
            'dns_match' => [Rule::excludeIf(! $dns), 'required', 'string', Rule::in(['contains', 'exact'])],
            'dns_expected' => [Rule::excludeIf(! $dns), 'nullable', 'string', 'max:'.DnsRecordSet::EXPECTED_LIMIT,
                Rule::requiredIf($dns && (! $monitor || ($this->validationData()['dns_record_type'] ?? null) !== $monitor->dns_record_type))],
            'tls_port' => [Rule::excludeIf(! $tls), 'required', 'integer', 'between:1,65535'],
            'tls_expiry_days' => [Rule::excludeIf(! $tls), 'required', 'integer', 'between:1,90'],
            'tcp_port' => [Rule::excludeIf(! $tcp), 'required', 'integer', 'between:1,65535'],
            'heartbeat_schedule' => [Rule::excludeIf(! $heartbeat), 'required', 'string', Rule::in(['interval', 'cron'])],
            'heartbeat_interval_minutes' => [Rule::excludeIf(! $heartbeat || $cron), 'required', 'integer', 'between:1,43200'],
            'heartbeat_cron' => [Rule::excludeIf(! $heartbeat || ! $cron), 'required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'heartbeat_timezone' => [Rule::excludeIf(! $heartbeat || ! $cron), 'required', 'string', 'max:64', 'timezone'],
            'heartbeat_grace_minutes' => [Rule::excludeIf(! $heartbeat), 'required', 'integer', 'between:1,10080'],
            'queue_name' => [Rule::excludeIf(! $queue), 'required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'queue_settings' => [Rule::excludeIf(! $queue), 'required', 'array:'.implode(',', array_keys(QueueMonitorSettings::LIMITS))],
            'timeout_seconds' => [Rule::excludeIf($signals), 'required', 'integer', 'between:1,20'],
            'interval_minutes' => [Rule::excludeIf($signals), 'required', 'integer', Rule::in(array_keys(self::INTERVALS))],
            'trigger_checks' => [Rule::excludeIf($signals), 'required', 'integer', 'between:1,10'],
            'recovery_checks' => [Rule::excludeIf($signals), 'required', 'integer', 'between:1,10'],
            'enabled' => ['required', 'boolean'],
            'version' => [$this->route('monitor') ? 'required' : 'exclude', 'integer', 'min:0'],
            'destinations' => ['sometimes', 'array', 'max:5'],
            'destinations.*' => ['required', 'integer', 'distinct', 'min:1'],
            'opened' => ['required', 'boolean'], 'recovered' => ['required', 'boolean'],
        ];
        foreach (QueueMonitorSettings::LIMITS as $field => [$minimum, $maximum]) {
            $required = in_array($field, ['report_timeout_seconds', 'worker_timeout_seconds', 'minimum_workers'], true);
            $rules['queue_settings.'.$field] = [Rule::excludeIf(! $queue), $required ? 'required' : 'nullable', 'integer', 'between:'.$minimum.','.$maximum];
        }

        return $rules;
    }

    /** @return array<callable(Validator): void> */
    public function after(PublicHttpTarget $targets, DnsRecordSet $sets, HeartbeatSchedule $schedules): array
    {
        return [function (Validator $validator) use ($targets, $sets, $schedules): void {
            $data = $validator->getData();
            $monitor = $this->route('monitor');
            if ($monitor instanceof Monitor && ! $validator->errors()->has('environment_id') && (int) $data['environment_id'] !== $monitor->environment_id) {
                $validator->errors()->add('environment_id', 'The environment cannot be changed. Create a separate monitor.');
            }
            if ($monitor instanceof Monitor && $this->checkType() !== $monitor->type) {
                $validator->errors()->add('check_type', 'The monitor type cannot be changed. Create a separate monitor.');
            }
            if ($validator->errors()->has('check_type')) {
                return;
            }
            if ($this->checkType() === 'queue') {
                return;
            }
            if ($this->checkType() === 'heartbeat') {
                if (($data['heartbeat_schedule'] ?? null) === 'cron' && ! $validator->errors()->hasAny(['heartbeat_cron', 'heartbeat_timezone'])) {
                    try {
                        $schedules->nextCron($data['heartbeat_cron'], $data['heartbeat_timezone'], CarbonImmutable::now('UTC'));
                    } catch (\InvalidArgumentException|\RuntimeException) {
                        $validator->errors()->add('heartbeat_cron', 'Use a valid five-field cron expression with a future occurrence.');
                    }
                }

                return;
            }
            if ($this->checkType() !== 'http') {
                if ($validator->errors()->hasAny(['hostname', 'dns_record_type', 'dns_expected', 'tls_port', 'tls_expiry_days', 'tcp_port'])) {
                    return;
                }
                $hostname = $sets->hostname($data['hostname'] ?? $monitor?->hostname ?? '');
                if ($hostname === null || in_array($this->checkType(), ['tls', 'tcp'], true)
                    && $targets->parse('https://'.$hostname.':'.($data[$this->checkType() === 'tls' ? 'tls_port' : 'tcp_port']).'/') === null) {
                    $validator->errors()->add('hostname', 'Enter a fully qualified public hostname, without a scheme, path, credentials or port.');
                }
                if ($this->checkType() === 'dns' && isset($data['dns_expected'])
                    && $sets->expected($data['dns_record_type'], $data['dns_expected']) === null) {
                    $validator->errors()->add('dns_expected', 'Enter 1–20 valid records, one per line. Use IP addresses, hostnames, priority + hostname for MX, or unquoted literal TXT values.');
                }

                return;
            }
            if ($validator->errors()->hasAny(['request_url', 'bearer_token', 'body_contains', 'method', 'clear_bearer_token', 'clear_body_contains'])) {
                return;
            }
            $url = $data['request_url'] ?? $monitor?->request_url ?? '';
            $target = $targets->parse($url);
            if ($target === null) {
                $validator->errors()->add('request_url', 'Use a public HTTP or HTTPS URL without user information or fragments.');
            }
            $token = ($data['clear_bearer_token'] ?? false) ? null : ($data['bearer_token'] ?? $monitor?->bearer_token);
            if ($monitor && isset($data['request_url']) && $targets->parse($monitor->request_url) !== $target && ! isset($data['bearer_token'])) {
                $token = null;
            }
            if ($token !== null && ($target['scheme'] ?? null) !== 'https') {
                $validator->errors()->add('bearer_token', 'Bearer credentials require HTTPS.');
            }
            $body = ($data['clear_body_contains'] ?? false) ? null : ($data['body_contains'] ?? $monitor?->body_contains);
            if (($data['method'] ?? null) === 'HEAD' && $body !== null) {
                $validator->errors()->add('body_contains', 'HEAD checks cannot assert response text. Clear the stored assertion or use GET.');
            }
            foreach (['bearer_token', 'body_contains'] as $field) {
                if (($data['clear_'.$field] ?? false) && isset($data[$field])) {
                    $validator->errors()->add($field, 'Choose either a replacement value or clear the stored value.');
                }
            }
        }];
    }

    private function checkType(): mixed
    {
        return $this->validationData()['check_type'] ?? $this->route('monitor')?->type ?? 'http';
    }
}
