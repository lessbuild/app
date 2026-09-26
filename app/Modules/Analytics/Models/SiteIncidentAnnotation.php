<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Analytics\Database\AnalyticsModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SiteIncidentAnnotation extends AnalyticsModel
{
    protected $fillable = [
        'site_id', 'delivery_id', 'handler', 'project_connection_id', 'source_incident_id',
        'status', 'occurred_at', 'payload_hash',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
