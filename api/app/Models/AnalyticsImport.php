<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An import of a site's history from Google Analytics: connected (a Google account, no property chosen yet), queued,
 * running, done or failed. The Google connection is forgotten once the import finishes.
 *
 * @property int $id
 * @property int $site_id
 * @property string|null $created_by
 * @property string $source
 * @property string|null $refresh_token (encrypted)
 * @property string|null $property the GA4 property ID
 * @property string|null $property_name
 * @property string $status connected, queued, running, done or failed
 * @property Carbon|null $from_date
 * @property Carbon|null $until_date
 * @property int $days_imported
 * @property string|null $error
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AnalyticsSite $site
 */
final class AnalyticsImport extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; imports are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * The attributes never shown when the import is serialised.
     *
     * @var list<string>
     */
    protected $hidden = ['refresh_token'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['refresh_token' => 'encrypted', 'from_date' => 'date', 'until_date' => 'date', 'days_imported' => 'integer', 'finished_at' => 'datetime'];
    }

    /**
     * Get the site the history is imported into.
     *
     * @return BelongsTo<AnalyticsSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSite::class, 'site_id');
    }
}
