<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IngestPayloadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $ingest_receipt_id
 * @property array<mixed> $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read IngestReceipt $ingestReceipt
 */
#[Fillable(['ingest_receipt_id', 'payload'])]
#[Hidden(['payload'])]
#[Table(key: 'ingest_receipt_id', keyType: 'string', incrementing: false, dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(IngestPayloadFactory::class)]
final class IngestPayload extends Model
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
