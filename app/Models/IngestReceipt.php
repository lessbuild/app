<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IngestSource;
use App\Enums\IngestStatus;
use Carbon\CarbonImmutable;
use Database\Factories\IngestReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $account_id
 * @property string|null $environment_id
 * @property string $receipt_key
 * @property string $payload_fingerprint
 * @property IngestSource $source
 * @property IngestStatus $status
 * @property int $event_count
 * @property int $accepted_count
 * @property int $duplicate_count
 * @property int $attempt_count
 * @property int $processing_attempts
 * @property int $generation
 * @property int $recovery_count
 * @property string|null $queue_job_uuid
 * @property string|null $processing_token
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable $last_received_at
 * @property CarbonImmutable|null $processing_started_at
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $last_error_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment|null $environment
 * @property-read Account $account
 * @property-read IngestPayload|null $ingestPayload
 */
#[Fillable([
    'account_id', 'environment_id', 'receipt_key', 'payload_fingerprint', 'source', 'status',
    'event_count', 'accepted_count', 'duplicate_count', 'attempt_count',
    'received_at', 'last_received_at', 'processed_at',
])]
#[Hidden(['receipt_key', 'payload_fingerprint', 'processing_token', 'queue_job_id', 'queue_job_uuid'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(IngestReceiptFactory::class)]
final class IngestReceipt extends Model
{
    /** @use HasFactory<IngestReceiptFactory> */
    use HasFactory, HasUlids;

    /**
     * The environment the batch was sent to.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * The account whose event allowance it counts against.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The raw batch, kept while it waits to be processed.
     *
     * @return HasOne<IngestPayload, $this>
     */
    public function ingestPayload(): HasOne
    {
        return $this->hasOne(IngestPayload::class);
    }

    /**
     * A sentence explaining the last processing error code, for the receipts page.
     */
    public function processingError(): ?string
    {
        return match ($this->last_error_code) {
            'storage_unavailable' => 'Storage was temporarily unavailable. No events or usage were partially committed.',
            'payload_unavailable' => 'The retained payload could not be read. Operator investigation is required.',
            'source_removed' => 'The source was permanently removed before processing. Its pending payload was discarded.',
            'plan_limit_reached' => 'The workspace monthly event limit was reached. Upgrade the plan, then retry this retained delivery.',
            'worker_interrupted' => 'The worker stopped before processing completed. The retained delivery can be retried.',
            'processing_failed' => 'Processing could not complete. The retained delivery can be retried.',
            default => null,
        };
    }

    /**
     * Reads `source` as an IngestSource and `status` as an IngestStatus.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => IngestSource::class,
            'status' => IngestStatus::class,
            'processing_attempts' => 'integer',
            'generation' => 'integer',
            'recovery_count' => 'integer',
            'event_count' => 'integer',
            'accepted_count' => 'integer',
            'duplicate_count' => 'integer',
            'attempt_count' => 'integer',
            'received_at' => 'immutable_datetime',
            'last_received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
