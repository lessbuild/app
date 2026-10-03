<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing the simulated visitor did on a journey: where they were, what they did and why, with a screenshot.
 *
 * @property int $id
 * @property int $site_audit_journey_id
 * @property int $position
 * @property string $url
 * @property array{type: string, label?: string, text?: string} $action
 * @property string|null $thought the visitor's reason for the action
 * @property string|null $screenshot_path on the private disk
 * @property list<array{x: int, y: int, width: int, height: int}>|null $boxes the element acted on, outlined on the screenshot
 * @property list<array{n: int, label: string, role: string, box: array{x: int, y: int, width: int, height: int}}>|null $elements
 *                                                                                                                                the numbered elements that were on screen, so findings can outline any of them
 * @property int $duration_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SiteAuditJourney $journey
 */
final class SiteAuditStep extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them.
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
        return ['position' => 'integer', 'action' => 'array', 'boxes' => 'array', 'elements' => 'array', 'duration_ms' => 'integer'];
    }

    /**
     * Get the journey the step belongs to.
     *
     * @return BelongsTo<SiteAuditJourney, $this>
     */
    public function journey(): BelongsTo
    {
        return $this->belongsTo(SiteAuditJourney::class, 'site_audit_journey_id');
    }
}
