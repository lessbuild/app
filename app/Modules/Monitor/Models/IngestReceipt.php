<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Data\Telemetry\IngestSource;
use App\Modules\Monitor\Data\Telemetry\IngestStatus;
use App\Modules\Monitor\Database\Factories\IngestReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'workspace_id', 'environment_id', 'receipt_key', 'payload_fingerprint', 'source', 'status',
    'event_count', 'accepted_count', 'duplicate_count', 'attempt_count',
    'received_at', 'last_received_at', 'processed_at',
])]
#[Hidden(['receipt_key', 'payload_fingerprint', 'processing_token', 'queue_job_id', 'queue_job_uuid'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class IngestReceipt extends Model
{
    /** @use HasFactory<IngestReceiptFactory> */
    use HasFactory, HasUlids;

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasOne<IngestPayload, $this> */
    public function ingestPayload(): HasOne
    {
        return $this->hasOne(IngestPayload::class);
    }

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => IngestSource::class,
            'status' => IngestStatus::class,
            'processing_attempts' => 'integer',
            'generation' => 'integer',
            'recovery_count' => 'integer',
            'queue_job_id' => 'integer',
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
