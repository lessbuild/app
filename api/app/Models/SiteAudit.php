<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SiteAuditSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A site Audit checks: the journeys a simulated visitor tries on it, the competitors it's compared with, and how often it runs.
 *
 * @property int $id
 * @property string $project_id
 * @property string $name
 * @property string $url the page the visitor starts on
 * @property list<array{key: string, goal: string}> $journeys
 * @property SiteAuditSchedule $schedule
 * @property Carbon|null $next_run_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteAuditCompetitor> $competitors
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteAuditRun> $runs
 * @property-read SiteAuditRun|null $latestRun
 */
final class SiteAudit extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; audits are written by Actions with forceFill.
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
        return ['journeys' => 'array', 'schedule' => SiteAuditSchedule::class, 'next_run_at' => 'datetime'];
    }

    /**
     * Get the project the audit belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the competitors the site is compared with, suggested ones included.
     *
     * @return HasMany<SiteAuditCompetitor, $this>
     */
    public function competitors(): HasMany
    {
        return $this->hasMany(SiteAuditCompetitor::class)->orderBy('id');
    }

    /**
     * Get the audit's runs, newest first.
     *
     * @return HasMany<SiteAuditRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(SiteAuditRun::class)->latest('id');
    }

    /**
     * Get the newest run.
     *
     * @return HasOne<SiteAuditRun, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(SiteAuditRun::class)->latestOfMany();
    }
}
