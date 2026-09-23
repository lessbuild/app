<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Data\Telemetry\IngestSource;
use App\Modules\Monitor\Database\Factories\TelemetryUsageEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'environment_id', 'ingest_receipt_id', 'source', 'event_count', 'received_at'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class TelemetryUsageEntry extends Model
{
    /** @use HasFactory<TelemetryUsageEntryFactory> */
    use HasFactory;

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<IngestReceipt, $this> */
    public function ingestReceipt(): BelongsTo
    {
        return $this->belongsTo(IngestReceipt::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => IngestSource::class,
            'event_count' => 'integer',
            'received_at' => 'immutable_datetime',
        ];
    }
}
