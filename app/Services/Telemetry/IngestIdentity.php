<?php

declare(strict_types=1);

namespace App\Services\Telemetry;

use App\Data\Telemetry\IngestContext;
use App\Enums\IngestSource;
use Illuminate\Validation\ValidationException;
use LogicException;

final class IngestIdentity
{
    /**
     * OTLP content identities come only from the server-side mapper. Its canonical
     * representation preserves equivalent attribute ordering and numeric timestamps.
     *
     * @param  list<array<string, mixed>>  $events
     * @param  IngestContext  $context
     * @return list<array<string, mixed>>
     */
    public function fingerprintEvents(array $events, IngestContext $context): array
    {
        if ($context->source === IngestSource::Json) {
            return $events;
        }

        return array_map(function (array $event): array {
            if (! isset($event['id'], $event['content_identity'])) {
                throw new LogicException('OTLP events require a server-generated content identity.');
            }

            return ['id' => $event['id'], 'content_identity' => $event['content_identity']];
        }, $events);
    }

    /**
     * JSON with object keys sorted at every level, so equal data always encodes the same way.
     *
     * @param  array<mixed>  $value
     * @return string
     */
    public function canonical(array $value): string
    {
        return json_encode($this->sortKeys($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Keyed fingerprints of a value under the current and previous app keys, so a batch sent before a key rotation is
     * still recognised after it.
     *
     * @param  array<mixed>  $value
     * @return list<string>
     */
    public function fingerprints(array $value): array
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new LogicException('An application key is required for ingestion fingerprints.');
        }

        $canonical = 'beacon-ingest-v2:'.$this->canonical($value);
        $keys = array_unique([$key, ...config('app.previous_keys', [])]);

        return array_values(array_map(
            fn (string $key): string => hash_hmac('sha256', $canonical, $key),
            $keys,
        ));
    }

    /**
     * Refuses a batch or event that reuses an identity with different content, which would otherwise be silently dropped
     * as a duplicate.
     *
     * @param  string|null  $stored
     * @param  list<string>  $fingerprints
     * @param  string  $field
     * @return void
     */
    public function assertMatches(?string $stored, array $fingerprints, string $field): void
    {
        foreach ($fingerprints as $fingerprint) {
            if ($stored !== null && hash_equals($stored, $fingerprint)) {
                return;
            }
        }

        throw ValidationException::withMessages([
            $field => 'This identity was already used with different telemetry. Retry the original payload unchanged.',
        ]);
    }

    /**
     * The keys a batch's receipt is found by: its ID when the client named the batch, else each content fingerprint.
     *
     * @param  string  $environmentId
     * @param  string  $batchId
     * @param  IngestContext  $context
     * @param  list<string>  $fingerprints
     * @return list<string>
     */
    public function receiptKeys(string $environmentId, string $batchId, IngestContext $context, array $fingerprints): array
    {
        $identifiers = $context->explicitBatch ? [['batch', $batchId]] : array_map(
            fn (string $fingerprint): array => ['content', $fingerprint],
            $fingerprints,
        );

        return array_map(fn (array $identifier): string => hash('sha256', $this->canonical([
            'receipt-v2', $environmentId, $context->source->value, $identifier,
        ])), $identifiers);
    }

    /**
     * An event's deduplication key: its ID (or position) within the batch or, for unnamed batches, within the
     * environment and source.
     *
     * @param  string  $environmentId
     * @param  string  $batchId
     * @param  IngestContext  $context
     * @param  array<string, mixed>  $event
     * @param  int  $index
     * @return string
     */
    public function eventKey(string $environmentId, string $batchId, IngestContext $context, array $event, int $index): string
    {
        return hash('sha256', $this->canonical([
            'event-v2', $environmentId, $context->source->value,
            $context->explicitBatch ? ['batch', $batchId] : ['implicit'],
            isset($event['id']) ? ['id', (string) $event['id']] : ['index', $index],
        ]));
    }

    /**
     * Sorts object keys recursively, leaving lists in order.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortKeys($item);
            }
        }

        return $value;
    }
}
