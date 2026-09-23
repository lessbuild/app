<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\IngestPayloadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ingest_receipt_id', 'payload'])]
#[Hidden(['payload'])]
#[Table(key: 'ingest_receipt_id', keyType: 'string', incrementing: false, dateFormat: 'Y-m-d H:i:s.u')]
class IngestPayload extends Model
{
    /** @use HasFactory<IngestPayloadFactory> */
    use HasFactory;

    /** @return BelongsTo<IngestReceipt, $this> */
    public function ingestReceipt(): BelongsTo
    {
        return $this->belongsTo(IngestReceipt::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array'];
    }
}
