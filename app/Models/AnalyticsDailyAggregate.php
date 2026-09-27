<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property Carbon $local_date
 * @property string $dimension
 * @property string|null $dimension_value
 */
class AnalyticsDailyAggregate extends Model
{
    protected $table = 'analytics_daily_aggregates';

    /** @var list<string> */
    protected $fillable = ['site_id', 'local_date', 'dimension', 'dimension_value', 'pageviews', 'visits', 'visitors', 'conversions', 'converted_visits', 'bounce_eligible', 'bounces'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['local_date' => 'date'];
    }

    /** @return BelongsTo<AnalyticsSite, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSite::class, 'site_id');
    }
}
