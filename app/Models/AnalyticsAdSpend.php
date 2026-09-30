<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What one campaign cost on one day, imported from the ad platform's export (Google Ads, Meta and so on). Matched to
 * visits by campaign and source (utm_campaign and utm_source), ignoring case.
 *
 * @property int $id
 * @property int $site_id
 * @property Carbon $date
 * @property string $source lower case, such as google or facebook
 * @property string $campaign
 * @property int $cost_cents
 * @property string $currency
 * @property int|null $clicks
 * @property int|null $impressions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AnalyticsAdSpend extends Model
{
    /**
     * The table's name.
     *
     * @var string
     */
    protected $table = 'analytics_ad_spend';

    /**
     * The attributes that can't be mass assigned: all of them; spend is written by the import.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'date', 'cost_cents' => 'integer', 'clicks' => 'integer', 'impressions' => 'integer'];
    }
}
