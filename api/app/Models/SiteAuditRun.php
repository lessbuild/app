<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SiteAuditStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One run of an audit: the journeys on the site and its competitors, the scores, and what to improve.
 *
 * @property int $id
 * @property int $site_audit_id
 * @property string $project_id
 * @property SiteAuditStatus $status
 * @property string $trigger manual or scheduled
 * @property int|null $score the site's overall score, 0–100
 * @property array<string, array{name: string, url: string, score: int, categories: array<string, int>}>|null $scores
 *                                                                                                                    per site (`site` or `competitor:<id>`)
 * @property string|null $summary
 * @property int $pages_visited
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $error
 * @property string|null $requested_by null for scheduled runs
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SiteAudit $audit
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteAuditJourney> $journeys
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteAuditFinding> $findings
 */
final class SiteAuditRun extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; runs are written with forceFill.
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
        return ['status' => SiteAuditStatus::class, 'score' => 'integer', 'scores' => 'array', 'pages_visited' => 'integer',
            'input_tokens' => 'integer', 'output_tokens' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /**
     * Get the audit that ran.
     *
     * @return BelongsTo<SiteAudit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(SiteAudit::class, 'site_audit_id');
    }

    /**
     * Get the journeys taken on every site, in the order they ran.
     *
     * @return HasMany<SiteAuditJourney, $this>
     */
    public function journeys(): HasMany
    {
        return $this->hasMany(SiteAuditJourney::class)->orderBy('id');
    }

    /**
     * Get what to improve, most important first.
     *
     * @return HasMany<SiteAuditFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(SiteAuditFinding::class)->orderByRaw("case severity when 'high' then 0 when 'medium' then 1 else 2 end")->orderBy('id');
    }

    /**
     * Get where the run's screenshots and mock-ups are kept on the private disk.
     *
     * @return string
     */
    public function storageDirectory(): string
    {
        return 'site-audits/'.$this->project_id.'/'.$this->id;
    }
}
