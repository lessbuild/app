<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IngestSource;
use Carbon\CarbonImmutable;
use Database\Factories\TelemetryUsageEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $account_id
 * @property string|null $environment_id
 * @property string|null $ingest_receipt_id
 * @property IngestSource $source
 * @property int $event_count
 * @property CarbonImmutable $received_at
 * @property-read Account $account
 */
#[Fillable(['account_id', 'environment_id', 'ingest_receipt_id', 'source', 'event_count', 'received_at'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(TelemetryUsageEntryFactory::class)]
final class TelemetryUsageEntry extends Model
{
    /** @use HasFactory<TelemetryUsageEntryFactory> */
    use HasFactory;

    /**
     * Get the account the usage counts against.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the environment that sent the events.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the batch counted.
     *
     * @return BelongsTo<IngestReceipt, $this>
     */
    public function ingestReceipt(): BelongsTo
    {
        return $this->belongsTo(IngestReceipt::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads `source` as an IngestSource.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => IngestSource::class,
            'event_count' => 'integer',
            'received_at' => 'immutable_datetime',
        ];
    }
}
