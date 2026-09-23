<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Analytics\Database\AnalyticsModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsEvent extends AnalyticsModel
{
    use HasFactory;

    protected $fillable = [
        'site_id', 'ingestion_batch_id', 'event_id', 'type', 'occurred_at', 'received_at',
        'path', 'referrer_host', 'utm_source', 'utm_medium', 'utm_campaign',
        'device_category', 'browser', 'operating_system', 'visitor_hash', 'session_id', 'properties',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'properties' => 'array',
    ];

    /** @return BelongsTo<Site> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<IngestionBatch> */
    public function ingestionBatch(): BelongsTo
    {
        return $this->belongsTo(IngestionBatch::class);
    }
}
