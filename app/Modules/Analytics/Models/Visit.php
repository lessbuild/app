<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Analytics\Database\AnalyticsModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Visit extends AnalyticsModel
{
    use HasFactory;

    protected $fillable = [
        'site_id', 'visit_key', 'visitor_hash', 'session_id', 'started_at', 'last_seen_at',
        'landing_path', 'exit_path', 'entry_referrer_host', 'entry_utm_source', 'entry_utm_medium', 'entry_utm_campaign', 'pageviews', 'conversion_count',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
