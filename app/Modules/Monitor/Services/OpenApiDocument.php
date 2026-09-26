<?php

namespace App\Modules\Monitor\Services;

final class OpenApiDocument
{
    /** @return array<string, mixed> */
    public function make(string $serverUrl, string $productName = 'Monitor'): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $productName.' Telemetry API',
                'version' => '1.0.0',
                'description' => 'Versioned ingestion endpoints for '.$productName.' events, OpenTelemetry signals, deployments, heartbeats and queue health.',
            ],
            'servers' => [['url' => rtrim($serverUrl, '/')]],
            'tags' => [
                ['name' => 'Telemetry', 'description' => $productName.' JSON and OpenTelemetry HTTP/JSON ingestion.'],
                ['name' => 'Deployments', 'description' => 'Release and deployment context.'],
                ['name' => 'Monitors', 'description' => 'Heartbeat and queue monitor signals.'],
            ],
            'paths' => $this->paths(),
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'environment token'],
                    'BeaconTokenHeader' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Beacon-Token', 'description' => 'Legacy environment-token header; Authorization: Bearer is recommended.'],
                    'HeartbeatBearer' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'monitor heartbeat key'],
                    'QueueBearer' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'monitor queue key'],
                ],
                'schemas' => [
                    'IngestBatch' => [
                        'type' => 'object',
                        'required' => ['batch_id', 'events'],
                        'properties' => [
                            'batch_id' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Stable identity for one retryable batch.'],
                            'events' => ['type' => 'array', 'minItems' => 1, 'maxItems' => (int) config('monitor.beacon.telemetry.max_events_per_batch'), 'items' => ['$ref' => '#/components/schemas/TelemetryEvent']],
                        ],
                    ],
                    'TelemetryEvent' => [
                        'type' => 'object',
                        'required' => ['type'],
                        'properties' => [
                            'id' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Stable event identity for safe replay.'],
                            'type' => ['type' => 'string', 'enum' => ['request', 'query', 'job', 'exception', 'log', 'metric']],
                            'severity' => ['type' => 'string', 'enum' => ['debug', 'info', 'warning', 'error', 'critical']],
                            'name' => ['type' => 'string', 'maxLength' => 255],
                            'service' => ['type' => 'string', 'maxLength' => 100],
                            'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                            'attributes' => ['type' => 'object', 'additionalProperties' => true],
                            'payload' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                        'additionalProperties' => true,
                    ],
                    'Deployment' => [
                        'type' => 'object',
                        'required' => ['deployment_id', 'version'],
                        'properties' => [
                            'deployment_id' => ['type' => 'string', 'format' => 'uuid'],
                            'version' => ['type' => 'string', 'maxLength' => 128],
                            'service' => ['type' => 'string', 'maxLength' => 100],
                            'service_namespace' => ['type' => 'string', 'maxLength' => 100],
                            'commit_sha' => ['type' => 'string', 'minLength' => 7, 'maxLength' => 64],
                            'note' => ['type' => 'string', 'maxLength' => 1000],
                            'deployed_at' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                    'DeploymentResponse' => [
                        'type' => 'object', 'required' => ['data'],
                        'properties' => ['data' => ['type' => 'object', 'required' => ['id', 'deployment_id', 'environment_id', 'release_id', 'version', 'deployed_at', 'replayed'], 'properties' => [
                            'id' => ['type' => 'integer'], 'deployment_id' => ['type' => 'string', 'format' => 'uuid'],
                            'environment_id' => ['type' => 'integer'], 'release_id' => ['type' => 'integer'],
                            'version' => ['type' => 'string'], 'service' => ['type' => ['string', 'null']],
                            'service_namespace' => ['type' => ['string', 'null']], 'commit_sha' => ['type' => ['string', 'null']],
                            'deployed_at' => ['type' => 'string', 'format' => 'date-time'], 'replayed' => ['type' => 'boolean'],
                        ]]],
                    ],
                    'IngestResponse' => [
                        'type' => 'object', 'required' => ['data'],
                        'properties' => ['data' => ['type' => 'object', 'required' => ['batch_id', 'accepted', 'duplicates', 'receipt_id', 'replayed', 'status'], 'properties' => [
                            'batch_id' => ['type' => 'string'], 'accepted' => ['type' => 'integer'],
                            'duplicates' => ['type' => 'integer'], 'receipt_id' => ['type' => ['string', 'null']],
                            'replayed' => ['type' => 'boolean'], 'status' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                        ]]],
                    ],
                    'IngestReceiptResponse' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'object', 'required' => ['id', 'source', 'status', 'submitted', 'accepted', 'duplicates', 'attempts', 'received_at', 'last_received_at', 'processed_at', 'processing'], 'properties' => [
                        'id' => ['type' => 'string', 'format' => 'ulid'], 'source' => ['type' => 'string'], 'status' => ['type' => 'string'],
                        'submitted' => ['type' => 'integer'], 'accepted' => ['type' => 'integer'], 'duplicates' => ['type' => 'integer'], 'attempts' => ['type' => 'integer'],
                        'received_at' => ['type' => 'string', 'format' => 'date-time'], 'last_received_at' => ['type' => 'string', 'format' => 'date-time'],
                        'processed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        'processing' => ['type' => 'object', 'required' => ['attempts', 'recoveries', 'next_attempt_at', 'failed_at', 'error_code', 'error_message'], 'properties' => [
                            'attempts' => ['type' => 'integer'], 'recoveries' => ['type' => 'integer'],
                            'next_attempt_at' => ['type' => ['string', 'null'], 'format' => 'date-time'], 'failed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                            'error_code' => ['type' => ['string', 'null']], 'error_message' => ['type' => ['string', 'null']],
                        ]],
                    ]]]],
                    'HeartbeatRequest' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['run_id', 'signal'], 'properties' => [
                        'run_id' => ['type' => 'string', 'format' => 'uuid'], 'signal' => ['type' => 'string', 'enum' => ['start', 'success', 'failure']],
                    ]],
                    'HeartbeatResponse' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'object', 'required' => ['run_id', 'signal', 'replayed', 'received_at'], 'properties' => [
                        'run_id' => ['type' => 'string', 'format' => 'uuid'], 'signal' => ['type' => 'string'], 'replayed' => ['type' => 'boolean'], 'received_at' => ['type' => 'string', 'format' => 'date-time'],
                    ]]]],
                    'QueueSnapshotRequest' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['snapshot_id', 'observed_at', 'pending'], 'properties' => [
                        'snapshot_id' => ['type' => 'string', 'format' => 'uuid'], 'observed_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'UTC timestamp ending in Z; no more than 30 seconds in the future.'],
                        'pending' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000000],
                        'delayed' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 1000000000],
                        'reserved' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 1000000000],
                        'failed' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 1000000000],
                        'oldest_wait_seconds' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 1000000000],
                    ]],
                    'QueueWorkerRequest' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['worker_id', 'sequence', 'status'], 'properties' => [
                        'worker_id' => ['type' => 'string', 'format' => 'uuid'], 'sequence' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647],
                        'status' => ['type' => 'string', 'enum' => ['idle', 'busy', 'stopped']],
                        'job_id' => ['type' => ['string', 'null'], 'format' => 'uuid', 'description' => 'Required only when status is busy; prohibited for idle or stopped.'],
                    ]],
                    'QueueSignalResponse' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'object', 'properties' => [
                        'snapshot_id' => ['type' => 'string', 'format' => 'uuid'], 'worker_id' => ['type' => 'string', 'format' => 'uuid'],
                        'sequence' => ['type' => 'integer'], 'status' => ['type' => 'string'], 'replayed' => ['type' => 'boolean'],
                        'applied' => ['type' => 'boolean'], 'received_at' => ['type' => 'string', 'format' => 'date-time'],
                    ]]]],
                    'OtlpResponse' => ['type' => 'object', 'additionalProperties' => false],
                    'OtlpPayload' => ['type' => 'object', 'description' => 'OTLP/HTTP JSON payload for the selected signal.', 'additionalProperties' => true],
                    'Error' => ['type' => 'object', 'properties' => [
                        'message' => ['type' => 'string'],
                        'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    ]],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function paths(): array
    {
        return [
            '/api/v1/ingest' => [
                'post' => $this->operation(
                    'Ingest a retryable '.config('app.name').' JSON batch.', 'ingestTelemetry', '#/components/schemas/IngestBatch', 'Telemetry',
                    [['BearerAuth' => []], ['BeaconTokenHeader' => []]],
                    rateLimits: [['key' => 'environment token', 'requests' => MonitorPublicApiLimits::INGEST_TOKEN_PER_MINUTE, 'window_seconds' => 60]],
                    responses: $this->contractResponses([
                        '200' => $this->jsonResponse('The batch completed without background processing.', '#/components/schemas/IngestResponse'),
                        '202' => $this->jsonResponse('The delivery was retained for asynchronous processing or retry.', '#/components/schemas/IngestResponse'),
                    ], validation: true, malformedPayload: true),
                ),
            ],
            '/api/v1/ingest/receipts/{receipt}' => [
                'get' => $this->operation(
                    'Inspect the processing state of an ingestion receipt.', 'showIngestReceipt', null, 'Telemetry',
                    [['BearerAuth' => []], ['BeaconTokenHeader' => []]],
                    parameters: [$this->pathParameter('receipt', 'ULID receipt ID')],
                    rateLimits: [['key' => 'environment token', 'requests' => MonitorPublicApiLimits::INGEST_TOKEN_PER_MINUTE, 'window_seconds' => 60]],
                    responses: $this->contractResponses([
                        '200' => $this->jsonResponse('The receipt is scoped to the authenticated environment.', '#/components/schemas/IngestReceiptResponse'),
                    ], validation: false, notFound: true),
                ),
            ],
            '/api/v1/otlp/v1/{signal}' => [
                'post' => $this->operation(
                    'Ingest OTLP/HTTP JSON traces, logs or metrics.', 'ingestOtlpSignal', '#/components/schemas/OtlpPayload', 'Telemetry',
                    [['BearerAuth' => []], ['BeaconTokenHeader' => []]],
                    parameters: [
                        $this->pathParameter('signal', 'Signal name', ['traces', 'logs', 'metrics']),
                        $this->headerParameter('X-Beacon-Batch', 'Optional stable batch identity for replay-safe retries.'),
                    ],
                    rateLimits: [['key' => 'environment token', 'requests' => MonitorPublicApiLimits::INGEST_TOKEN_PER_MINUTE, 'window_seconds' => 60]],
                    responses: $this->contractResponses([
                        '200' => $this->jsonResponse('The OTLP/HTTP response body is an empty JSON object.', '#/components/schemas/OtlpResponse', [
                            'X-Beacon-Accepted' => ['description' => 'Number of newly accepted events.', 'schema' => ['type' => 'integer']],
                            'X-Beacon-Duplicates' => ['description' => 'Number of duplicate events.', 'schema' => ['type' => 'integer']],
                            'X-Beacon-Receipt' => ['description' => 'Receipt ULID when a batch receipt was created.', 'schema' => ['type' => 'string']],
                            'X-Beacon-Replayed' => ['description' => 'Whether the batch was an idempotent replay.', 'schema' => ['type' => 'boolean']],
                            'X-Beacon-Status' => ['description' => 'Receipt processing state when a receipt exists.', 'schema' => ['type' => 'string']],
                        ]),
                    ], validation: true, malformedPayload: true),
                ),
            ],
            '/api/v1/deployments' => [
                'post' => $this->operation(
                    'Record deployment context for later incident correlation.', 'recordDeployment', '#/components/schemas/Deployment', 'Deployments',
                    [['BearerAuth' => []], ['BeaconTokenHeader' => []]],
                    rateLimits: [
                        ['key' => 'environment token', 'requests' => MonitorPublicApiLimits::INGEST_TOKEN_PER_MINUTE, 'window_seconds' => 60],
                        ['key' => 'environment token deployment ingress', 'requests' => MonitorPublicApiLimits::DEPLOYMENT_TOKEN_PER_MINUTE, 'window_seconds' => 60],
                    ],
                    responses: $this->contractResponses([
                        '200' => $this->jsonResponse('An existing deployment ID was replayed without duplicate rows.', '#/components/schemas/DeploymentResponse'),
                        '201' => $this->jsonResponse('The deployment and release were created.', '#/components/schemas/DeploymentResponse'),
                    ], validation: true, forbidden: true, malformedPayload: true),
                ),
            ],
            '/api/v1/heartbeats/{heartbeat}' => [
                'post' => $this->operation(
                    'Record a monitor heartbeat signal.', 'recordHeartbeat', '#/components/schemas/HeartbeatRequest', 'Monitors', [['HeartbeatBearer' => []]],
                    parameters: [$this->pathParameter('heartbeat', 'Heartbeat monitor ID', null, 'integer')],
                    rateLimits: [
                        ['key' => 'source IP', 'requests' => MonitorPublicApiLimits::HEARTBEAT_IP_PER_MINUTE, 'window_seconds' => 60],
                        ['key' => 'heartbeat monitor', 'requests' => MonitorPublicApiLimits::HEARTBEAT_MONITOR_PER_MINUTE, 'window_seconds' => 60],
                    ],
                    responses: $this->contractResponses([
                        '200' => $this->jsonResponse('The heartbeat signal was recorded or safely replayed.', '#/components/schemas/HeartbeatResponse'),
                    ], validation: true, malformedPayload: true),
                ),
            ],
            '/api/v1/queues/{queue}/snapshots' => [
                'post' => $this->operation(
                    'Submit a queue health snapshot.', 'recordQueueSnapshot', '#/components/schemas/QueueSnapshotRequest', 'Monitors', [['QueueBearer' => []]],
                    parameters: [$this->pathParameter('queue', 'Queue monitor ID', null, 'integer')],
                    rateLimits: [
                        ['key' => 'source IP', 'requests' => MonitorPublicApiLimits::QUEUE_IP_PER_MINUTE, 'window_seconds' => 60],
                        ['key' => 'queue monitor snapshot ingress', 'requests' => MonitorPublicApiLimits::QUEUE_SNAPSHOT_MONITOR_PER_MINUTE, 'window_seconds' => 60],
                    ],
                    responses: $this->contractResponses([
                        '200' => $this->jsonResponse('The queue sample was recorded or safely replayed.', '#/components/schemas/QueueSignalResponse'),
                    ], validation: true, malformedPayload: true),
                ),
            ],
            '/api/v1/queues/{queue}/workers' => [
                'post' => $this->operation(
                    'Report a queue worker heartbeat.', 'recordQueueWorker', '#/components/schemas/QueueWorkerRequest', 'Monitors', [['QueueBearer' => []]],
                    parameters: [$this->pathParameter('queue', 'Queue monitor ID', null, 'integer')],
                    rateLimits: [
                        ['key' => 'source IP', 'requests' => MonitorPublicApiLimits::QUEUE_IP_PER_MINUTE, 'window_seconds' => 60],
                        ['key' => 'queue monitor worker ingress', 'requests' => MonitorPublicApiLimits::QUEUE_WORKER_MONITOR_PER_MINUTE, 'window_seconds' => 60],
                    ],
                    responses: $this->contractResponses([
                        '200' => $this->jsonResponse('The worker heartbeat was recorded or safely replayed.', '#/components/schemas/QueueSignalResponse'),
                    ], validation: true, malformedPayload: true),
                ),
            ],
        ];
    }

    /** @param list<array<string, mixed>> $parameters
     * @param  list<array{key: string, requests: int, window_seconds: int}>  $rateLimits
     * @param  array<string, array<string, mixed>>  $responses
     * @param  list<array<string, list<mixed>>>  $security
     */
    private function operation(string $summary, string $operationId, ?string $schema, string $tag, array $security, array $parameters = [], array $rateLimits = [], array $responses = []): array
    {
        $operation = [
            'tags' => [$tag],
            'summary' => $summary,
            'operationId' => $operationId,
            'security' => $security,
            'responses' => $responses,
        ];
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }
        if ($schema !== null) {
            $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => $schema]]]];
        }
        if ($rateLimits !== []) {
            $operation['x-rate-limits'] = $rateLimits;
        }

        return $operation;
    }

    /** @param array<string, array<string, mixed>> $headers */
    private function jsonResponse(string $description, string $schema, array $headers = []): array
    {
        return [
            'description' => $description,
            ...($headers === [] ? [] : ['headers' => $headers]),
            'content' => ['application/json' => ['schema' => ['$ref' => $schema]]],
        ];
    }

    /** @param array<string, array<string, mixed>> $successResponses
     * @return array<string, array<string, mixed>>
     */
    private function contractResponses(array $successResponses, bool $validation = true, bool $notFound = false, bool $forbidden = false, bool $malformedPayload = false): array
    {
        $responses = array_replace($successResponses, $this->errorResponses($validation, $notFound, $forbidden, $malformedPayload));
        ksort($responses, SORT_NUMERIC);

        return $responses;
    }

    /** @return array<string, array<string, mixed>> */
    private function errorResponses(bool $validation = true, bool $notFound = false, bool $forbidden = false, bool $malformedPayload = false): array
    {
        $jsonError = fn (string $description): array => [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
        ];
        $responses = [
            '401' => $jsonError('The required environment or monitor credential is missing, invalid, revoked, expired, or no longer has access.'),
            '429' => [
                'description' => 'A per-token, per-monitor, or per-IP rate limit was exceeded.',
                'headers' => [
                    'Retry-After' => ['description' => 'Seconds until another request may be attempted.', 'schema' => ['type' => 'integer']],
                    'X-RateLimit-Limit' => ['schema' => ['type' => 'integer']],
                    'X-RateLimit-Remaining' => ['schema' => ['type' => 'integer']],
                ],
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
            ],
        ];
        if ($validation) {
            $responses['422'] = $jsonError('Request validation failed.');
        }
        if ($notFound) {
            $responses['404'] = $jsonError('The receipt does not exist in the authenticated environment.');
        }
        if ($forbidden) {
            $responses['403'] = $jsonError('The authenticated environment is not permitted to record this deployment.');
        }
        if ($malformedPayload) {
            $responses['400'] = $jsonError('The request body could not be read or decoded as valid JSON.');
            $responses['413'] = $jsonError('The request body exceeds the configured size, complexity, or event limit.');
            $responses['415'] = $jsonError('The request must use application/json and a supported content encoding.');
        }

        return $responses;
    }

    /** @param list<string>|null $enum */
    private function pathParameter(string $name, string $description, ?array $enum = null, string $type = 'string'): array
    {
        return ['name' => $name, 'in' => 'path', 'required' => true, 'description' => $description, 'schema' => array_filter(['type' => $type, 'enum' => $enum])];
    }

    /** @return array<string, mixed> */
    private function headerParameter(string $name, string $description): array
    {
        return ['name' => $name, 'in' => 'header', 'required' => false, 'description' => $description, 'schema' => ['type' => 'string', 'maxLength' => 100]];
    }
}
