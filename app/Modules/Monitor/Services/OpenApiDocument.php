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
                    'HeartbeatBearer' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'monitor heartbeat key'],
                    'QueueBearer' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'monitor queue key'],
                ],
                'schemas' => [
                    'IngestBatch' => [
                        'type' => 'object',
                        'required' => ['batch_id', 'events'],
                        'properties' => [
                            'batch_id' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Stable identity for one retryable batch.'],
                            'events' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => ['$ref' => '#/components/schemas/TelemetryEvent']],
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
                    'IngestResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object', 'properties' => [
                                'batch_id' => ['type' => 'string'], 'accepted' => ['type' => 'integer'],
                                'duplicates' => ['type' => 'integer'], 'receipt_id' => ['type' => 'string'],
                                'replayed' => ['type' => 'boolean'], 'status' => ['type' => 'string'],
                            ]],
                        ],
                    ],
                    'OtlpPayload' => ['type' => 'object', 'description' => 'OTLP/HTTP JSON payload for the selected signal.', 'additionalProperties' => true],
                    'Error' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function paths(): array
    {
        return [
            '/api/v1/ingest' => [
                'post' => $this->operation('Ingest a retryable '.config('app.name').' JSON batch.', 'ingestTelemetry', '#/components/schemas/IngestBatch', 'Telemetry', 'BearerAuth'),
            ],
            '/api/v1/ingest/receipts/{receipt}' => [
                'get' => $this->operation('Inspect the processing state of an ingestion receipt.', 'showIngestReceipt', null, 'Telemetry', 'BearerAuth', [$this->pathParameter('receipt', 'ULID receipt ID')]),
            ],
            '/api/v1/otlp/v1/{signal}' => [
                'post' => $this->operation('Ingest OTLP/HTTP JSON traces, logs or metrics.', 'ingestOtlpSignal', '#/components/schemas/OtlpPayload', 'Telemetry', 'BearerAuth', [$this->pathParameter('signal', 'Signal name', ['traces', 'logs', 'metrics'])]),
            ],
            '/api/v1/deployments' => [
                'post' => $this->operation('Record deployment context for later incident correlation.', 'recordDeployment', '#/components/schemas/Deployment', 'Deployments', 'BearerAuth'),
            ],
            '/api/v1/heartbeats/{heartbeat}' => [
                'post' => $this->operation('Record a monitor heartbeat signal.', 'recordHeartbeat', null, 'Monitors', 'HeartbeatBearer', [$this->pathParameter('heartbeat', 'Heartbeat monitor ID', null, 'integer')]),
            ],
            '/api/v1/queues/{queue}/snapshots' => [
                'post' => $this->operation('Submit a queue health snapshot.', 'recordQueueSnapshot', null, 'Monitors', 'QueueBearer', [$this->pathParameter('queue', 'Queue monitor ID', null, 'integer')]),
            ],
            '/api/v1/queues/{queue}/workers' => [
                'post' => $this->operation('Report a queue worker heartbeat.', 'recordQueueWorker', null, 'Monitors', 'QueueBearer', [$this->pathParameter('queue', 'Queue monitor ID', null, 'integer')]),
            ],
        ];
    }

    /** @param list<array<string, mixed>> $parameters */
    private function operation(string $summary, string $operationId, ?string $schema, string $tag, string $securityScheme, array $parameters = []): array
    {
        $operation = [
            'tags' => [$tag],
            'summary' => $summary,
            'operationId' => $operationId,
            'security' => [[$securityScheme => []]],
            'responses' => [
                '200' => ['description' => 'Accepted or processed successfully.'],
                '202' => ['description' => 'Accepted for asynchronous processing.'],
                '401' => ['description' => 'The supplied token is invalid.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                '422' => ['description' => 'The request body is invalid.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
            ],
        ];
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }
        if ($schema !== null) {
            $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => $schema]]]];
        }
        if ($operationId === 'ingestTelemetry') {
            $operation['responses']['200']['content'] = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/IngestResponse']]];
        }

        return $operation;
    }

    /** @param list<string>|null $enum */
    private function pathParameter(string $name, string $description, ?array $enum = null, string $type = 'string'): array
    {
        return ['name' => $name, 'in' => 'path', 'required' => true, 'description' => $description, 'schema' => array_filter(['type' => $type, 'enum' => $enum])];
    }
}
