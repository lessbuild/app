<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Analytics\Database\AnalyticsModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngestionBatch extends AnalyticsModel
{
    use HasFactory;

    protected $fillable = [
        'site_id', 'batch_id', 'event_count', 'status', 'accepted_at', 'processed_at', 'failure_message',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /** @return BelongsTo<Site> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<AnalyticsEvent> */
    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }
}
