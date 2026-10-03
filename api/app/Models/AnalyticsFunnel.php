<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A funnel on a site: steps, in order, that a visitor takes, such as a product page, the cart and the thank-you page.
 *
 * @property int $id
 * @property int $site_id
 * @property string $name
 * @property list<array{kind: string, match: string, value: string}> $steps kind pageview or event; match exact or prefix (pages only)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AnalyticsSite $site
 */
class AnalyticsFunnel extends Model
{
    /**
     * Get the site it belongs to.
     *
     * @return BelongsTo<AnalyticsSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSite::class, 'site_id');
    }

    /**
     * Describe a step for people, e.g. "Page /cart" or "Event signup".
     *
     * @param  array{kind: string, match: string, value: string}  $step
     * @return string
     */
    public static function stepLabel(array $step): string
    {
        return $step['kind'] === 'event'
            ? __('Event :name', ['name' => $step['value']])
            : ($step['match'] === 'prefix' ? __('Pages starting :path', ['path' => $step['value']]) : __('Page :path', ['path' => $step['value']]));
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads the steps as a list.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['steps' => 'array'];
    }
}
