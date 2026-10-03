<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of a Security check on a project: queued, running, done or failed, with how many problems it found.
 *
 * @property int $id
 * @property string $project_id
 * @property string $kind a scanner's kind, e.g. dependencies
 * @property string $status queued, running, done or failed
 * @property int $findings_count open findings the scan left
 * @property string|null $error
 * @property string|null $requested_by null for scheduled scans
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
final class SecurityScan extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; scans are written with forceFill.
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
        return ['findings_count' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /**
     * Get the project that was scanned.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
