<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property string $event_id
 * @property string $type
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 * @property string $path
 * @property string|null $referrer_host
 * @property string|null $visitor_hash
 * @property string|null $session_id
 * @property array<string, mixed>|null $properties
 */
class AnalyticsEvent extends Model
{
    protected $table = 'analytics_events';

    /** @var list<string> */
    protected $fillable = ['site_id', 'ingestion_batch_id', 'event_id', 'type', 'occurred_at', 'received_at', 'path', 'referrer_host', 'utm_source', 'utm_medium', 'utm_campaign', 'device_category', 'browser', 'operating_system', 'visitor_hash', 'session_id', 'properties'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'received_at' => 'datetime', 'properties' => 'array'];
    }

    /** @return BelongsTo<AnalyticsSite, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSite::class, 'site_id');
    }

    /** @return BelongsTo<AnalyticsIngestionBatch, $this> */
    public function ingestionBatch(): BelongsTo
    {
        return $this->belongsTo(AnalyticsIngestionBatch::class, 'ingestion_batch_id');
    }
}
