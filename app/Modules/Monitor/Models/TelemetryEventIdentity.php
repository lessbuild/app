<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\TelemetryEventIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['environment_id', 'ingest_receipt_id', 'telemetry_event_id', 'dedupe_key', 'version', 'payload_fingerprint'])]
#[Hidden(['dedupe_key', 'payload_fingerprint'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class TelemetryEventIdentity extends Model
{
    /** @use HasFactory<TelemetryEventIdentityFactory> */
    use HasFactory;

    /** @return BelongsTo<TelemetryEvent, $this> */
    public function telemetryEvent(): BelongsTo
    {
        return $this->belongsTo(TelemetryEvent::class);
    }

    /** @return BelongsTo<IngestReceipt, $this> */
    public function ingestReceipt(): BelongsTo
    {
        return $this->belongsTo(IngestReceipt::class);
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
