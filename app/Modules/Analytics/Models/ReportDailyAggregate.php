<?php

namespace App\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportDailyAggregate extends AnalyticsModel
{
    use HasFactory;

    protected $fillable = [
        'site_id', 'local_date', 'dimension', 'dimension_value', 'pageviews', 'visits', 'visitors',
        'conversions', 'converted_visits', 'bounce_eligible', 'bounces',
    ];

    protected function casts(): array
    {
        return ['local_date' => 'date'];
    }

    /** @return BelongsTo<Site> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
