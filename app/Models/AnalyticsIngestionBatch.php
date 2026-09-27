<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property string $batch_id
 * @property int $event_count
 * @property string $status
 * @property Carbon $accepted_at
 * @property Carbon|null $processed_at
 * @property-read AnalyticsSite $site
 */
class AnalyticsIngestionBatch extends Model
{
    protected $table = 'analytics_ingestion_batches';

    /** @var list<string> */
    protected $fillable = ['site_id', 'batch_id', 'event_count', 'status', 'accepted_at', 'processed_at', 'failure_message'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    /** @return BelongsTo<AnalyticsSite, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSite::class, 'site_id');
    }

    /** @return HasMany<AnalyticsEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class, 'ingestion_batch_id');
    }
}
