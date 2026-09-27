<?php

declare(strict_types=1);

namespace App\Http\Requests\Telemetry;

use App\Data\Telemetry\OtlpTimestamp;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreOtlpRequest extends FormRequest
{
    /**
     * Always allowed: the ingest key was checked by middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The decoded JSON body.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->json()->all();
    }

    /** Preserve protocol metadata and extension fields after validating their enclosing structures. */
    public function withValidator(Validator $validator): void
    {
        $validator->excludeUnvalidatedArrayKeys = false;
    }

    /**
     * The structure of an OTLP/JSON export for the signal in the URL: resources, scopes and records as lists, IDs as hex
     * of the right length, attribute lists capped per record, and every value a valid OTLP value. Spans must have start
     * and end times; metrics are checked per data-point family.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        [$resource, $scope, $records] = $this->paths();
        $scopePath = $resource.'.*.'.$scope.'.*';
        $recordPath = $scopePath.'.'.$records.'.*';
        $rules = [
            $resource => ['sometimes', 'nullable', 'array', 'list'],
            $resource.'.*' => ['array'],
            $resource.'.*.resource' => ['sometimes', 'nullable', 'array'],
            $resource.'.*.'.$scope => ['sometimes', 'nullable', 'array', 'list'],
            $scopePath => ['array'],
            $scopePath.'.scope' => ['sometimes', 'nullable', 'array'],
            $scopePath.'.'.$records => ['sometimes', 'nullable', 'array', 'list'],
            $recordPath => ['array'],
            $recordPath.'.name' => ['sometimes', 'nullable', 'string'],
            $recordPath.'.traceId' => $this->identifierRules(32, $this->route('signal') === 'traces'),
            $recordPath.'.spanId' => $this->identifierRules(16, $this->route('signal') === 'traces'),
            $recordPath.'.parentSpanId' => $this->identifierRules(16),
            $recordPath.'.severityText' => ['sometimes', 'nullable', 'string'],
            $recordPath.'.body' => ['sometimes', 'nullable', 'array', $this->valueRule()],
            $recordPath.'.status' => ['sometimes', 'nullable', 'array'],
        ];

        foreach ([$resource.'.*.resource.attributes', $scopePath.'.scope.attributes', $recordPath.'.attributes'] as $attributes) {
            $rules += $this->attributeRules($attributes);
        }

        if ($this->route('signal') === 'traces') {
            $rules[$recordPath.'.startTimeUnixNano'] = ['required'];
            $rules[$recordPath.'.endTimeUnixNano'] = ['required'];
        }

        if ($this->route('signal') === 'metrics') {
            foreach (['gauge', 'sum', 'histogram', 'exponentialHistogram', 'summary'] as $type) {
                $metricPath = $recordPath.'.'.$type;
                $rules[$metricPath] = ['sometimes', 'nullable', 'array'];
                $rules[$metricPath.'.dataPoints'] = ['sometimes', 'nullable', 'array', 'list'];
                $rules[$metricPath.'.dataPoints.*'] = ['array'];
                $rules += $this->attributeRules($metricPath.'.dataPoints.*.attributes');
            }
        }

        return $rules;
    }

    /**
     * Checks what rules can't express: the optional batch header, timestamps as unsigned 64-bit nanoseconds, spans that
     * don't end before they start, integer enum fields, log severity numbers, and at most one data family per metric.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $batchId = $this->header('X-Beacon-Batch');

            if ($batchId !== null && (strlen($batchId) > 100 || preg_match('/[\x00-\x1F\x7F]/', $batchId) === 1)) {
                $validator->errors()->add('X-Beacon-Batch', 'The batch header must contain at most 100 characters without control characters.');
            }

            [$resources, $scopes, $records] = $this->paths();

            foreach ($this->json($resources) ?? [] as $resourceIndex => $resource) {
                foreach ($resource[$scopes] ?? [] as $scopeIndex => $scope) {
                    foreach ($scope[$records] ?? [] as $recordIndex => $record) {
                        $path = "{$resources}.{$resourceIndex}.{$scopes}.{$scopeIndex}.{$records}.{$recordIndex}";
                        $this->validateRecord($validator, $record, $path, $this->route('signal'));
                    }
                }
            }
        }];
    }

    /**
     * Rules for a trace or span ID: hex of exactly `$length` characters and not all zeros, required for spans.
     *
     * @return array<int, string>
     */
    private function identifierRules(int $length, bool $required = false): array
    {
        return [...($required ? ['required'] : ['sometimes', 'nullable']), 'string', "regex:/\A(?!0{{$length}}\z)[a-f0-9]{{$length}}\z/iD"];
    }

    /**
     * Checks one record's timestamps and enum fields for its signal; metric data points are checked recursively as
     * "points".
     *
     * @param  array<string, mixed>  $record
     */
    private function validateRecord(Validator $validator, array $record, string $path, string $signal): void
    {
        if ($signal === 'metrics') {
            $families = array_filter(['gauge', 'sum', 'histogram', 'exponentialHistogram', 'summary'], fn (string $family): bool => isset($record[$family]));

            if (count($families) > 1) {
                $validator->errors()->add($path, 'A metric must contain at most one data type.');
            }

            foreach ($families as $family) {
                if (isset($record[$family]['aggregationTemporality']) && ! is_int($record[$family]['aggregationTemporality'])) {
                    $validator->errors()->add($path.'.'.$family.'.aggregationTemporality', 'OTLP enum fields must use integer JSON values.');
                }

                foreach ($record[$family]['dataPoints'] ?? [] as $index => $point) {
                    $this->validateRecord($validator, $point, $path.'.'.$family.'.dataPoints.'.$index, 'points');
                }
            }

            return;
        }

        $fields = match ($signal) {
            'traces' => ['startTimeUnixNano', 'endTimeUnixNano'],
            'logs' => ['timeUnixNano', 'observedTimeUnixNano'],
            default => ['timeUnixNano', 'startTimeUnixNano'],
        };
        $times = [];

        foreach ($fields as $field) {
            if (! isset($record[$field])) {
                continue;
            }

            if (! OtlpTimestamp::isValid($record[$field])) {
                $validator->errors()->add($path.'.'.$field, 'The timestamp must be an unsigned 64-bit integer in nanoseconds.');

                continue;
            }

            $times[$field] = OtlpTimestamp::fromUnixNano($record[$field]);
        }

        if ($signal === 'traces') {
            if (isset($times['startTimeUnixNano'], $times['endTimeUnixNano']) && $times['startTimeUnixNano']->millisecondsUntil($times['endTimeUnixNano']) < 0) {
                $validator->errors()->add($path.'.endTimeUnixNano', 'A span cannot end before it starts.');
            }

            foreach (['kind' => $record['kind'] ?? null, 'status.code' => $record['status']['code'] ?? null] as $field => $value) {
                if ($value !== null && ! is_int($value)) {
                    $validator->errors()->add($path.'.'.$field, 'OTLP enum fields must use integer JSON values.');
                }
            }
        }

        if ($signal === 'logs' && isset($record['severityNumber']) && (! is_int($record['severityNumber']) || $record['severityNumber'] < 0 || $record['severityNumber'] > 24)) {
            $validator->errors()->add($path.'.severityNumber', 'The severity number must be an integer between 0 and 24.');
        }
    }

    /**
     * The JSON keys for resources, scopes and records of the signal in the URL; unknown signals are a 404.
     *
     * @return array{string, string, string}
     */
    private function paths(): array
    {
        return match ($this->route('signal')) {
            'traces' => ['resourceSpans', 'scopeSpans', 'spans'],
            'logs' => ['resourceLogs', 'scopeLogs', 'logRecords'],
            'metrics' => ['resourceMetrics', 'scopeMetrics', 'metrics'],
            default => abort(404),
        };
    }

    /**
     * Rules for an attribute list: at most the configured number of attributes, each with a key of at most 256
     * characters and a valid OTLP value.
     *
     * @return array<string, array<int, mixed>>
     */
    private function attributeRules(string $path): array
    {
        return [
            $path => ['sometimes', 'nullable', 'array', 'list', 'max:'.config('monitoring.telemetry.max_attributes_per_record')],
            $path.'.*' => ['array'],
            $path.'.*.key' => ['required', 'string', 'max:256'],
            $path.'.*.value' => ['sometimes', 'nullable', 'array', $this->valueRule()],
        ];
    }

    /**
     * A validation rule that fails for anything that isn't a valid OTLP `AnyValue`.
     */
    private function valueRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $this->validValue($value)) {
                $fail('The :attribute field must contain a valid OTLP value.');
            }
        };
    }

    /**
     * Whether a value is a valid OTLP `AnyValue`: null, or an object with at most one typed field whose content fits the
     * type. Arrays and key-value lists are checked recursively, and key-value lists share the attribute limit.
     */
    private function validValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        $types = array_intersect(
            ['stringValue', 'bytesValue', 'boolValue', 'intValue', 'doubleValue', 'arrayValue', 'kvlistValue'],
            array_keys(array_filter($value, fn (mixed $item): bool => $item !== null)),
        );

        if (count($types) > 1) {
            return false;
        }

        if ($types === []) {
            return true;
        }

        $type = reset($types);
        $item = $value[$type];

        if (in_array($type, ['stringValue', 'bytesValue'], true)) {
            return is_string($item);
        }

        if ($type === 'boolValue') {
            return is_bool($item);
        }

        if ($type === 'intValue') {
            return is_int($item) || (is_string($item) && preg_match('/^-?\d+$/D', $item) === 1);
        }

        if ($type === 'doubleValue') {
            return is_numeric($item) || in_array($item, ['NaN', 'Infinity', '-Infinity'], true);
        }

        if (! is_array($item) || ! is_array($items = $item['values'] ?? []) || ! array_is_list($items)) {
            return false;
        }

        if ($type === 'kvlistValue' && count($items) > (int) config('monitoring.telemetry.max_attributes_per_record')) {
            return false;
        }

        foreach ($items as $child) {
            if ($type === 'kvlistValue') {
                if (! is_array($child) || ! is_string($child['key'] ?? null) || mb_strlen($child['key']) > 256) {
                    return false;
                }

                $child = $child['value'] ?? null;
            }

            if (! $this->validValue($child)) {
                return false;
            }
        }

        return true;
    }
}
