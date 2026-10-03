<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SiteAuditOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One task a simulated visitor tried on one site during a run, and how it went.
 *
 * @property int $id
 * @property int $site_audit_run_id
 * @property int|null $site_audit_competitor_id null for the audited site itself
 * @property string $site_url
 * @property string $goal_key
 * @property string $goal
 * @property SiteAuditOutcome $outcome
 * @property int|null $score 0–100
 * @property int $steps_count
 * @property int $duration_ms
 * @property string|null $summary what the visitor said about the journey
 * @property list<string>|null $friction the moments that slowed the visitor down
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SiteAuditRun $run
 * @property-read SiteAuditCompetitor|null $competitor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteAuditStep> $steps
 */
final class SiteAuditJourney extends Model
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
        return ['outcome' => SiteAuditOutcome::class, 'score' => 'integer', 'steps_count' => 'integer', 'duration_ms' => 'integer', 'friction' => 'array'];
    }

    /**
     * Get the run the journey was part of.
     *
     * @return BelongsTo<SiteAuditRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SiteAuditRun::class, 'site_audit_run_id');
    }

    /**
     * Get the competitor whose site this was, if it wasn't the audited site.
     *
     * @return BelongsTo<SiteAuditCompetitor, $this>
     */
    public function competitor(): BelongsTo
    {
        return $this->belongsTo(SiteAuditCompetitor::class, 'site_audit_competitor_id');
    }

    /**
     * Get the journey's steps in order.
     *
     * @return HasMany<SiteAuditStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(SiteAuditStep::class)->orderBy('position');
    }
}
