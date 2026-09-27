<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TelemetryEventIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $environment_id
 * @property string|null $ingest_receipt_id
 * @property int|null $telemetry_event_id
 * @property string $dedupe_key
 * @property int $version
 * @property string|null $payload_fingerprint
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TelemetryEvent|null $telemetryEvent
 * @property-read IngestReceipt|null $ingestReceipt
 * @property-read Environment|null $environment
 */
#[Fillable(['environment_id', 'ingest_receipt_id', 'telemetry_event_id', 'dedupe_key', 'version', 'payload_fingerprint'])]
#[Hidden(['dedupe_key', 'payload_fingerprint'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(TelemetryEventIdentityFactory::class)]
final class TelemetryEventIdentity extends Model
{
    /** @use HasFactory<TelemetryEventIdentityFactory> */
    use HasFactory;

    /**
     * The stored event this identity points to.
     *
     * @return BelongsTo<TelemetryEvent, $this>
     */
    public function telemetryEvent(): BelongsTo
    {
        return $this->belongsTo(TelemetryEvent::class);
    }

    /**
     * The batch it arrived in.
     *
     * @return BelongsTo<IngestReceipt, $this>
     */
    public function ingestReceipt(): BelongsTo
    {
        return $this->belongsTo(IngestReceipt::class);
    }

    /**
     * The environment it was sent to.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
