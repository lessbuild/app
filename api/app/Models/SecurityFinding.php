<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One security problem in a project, such as a vulnerable package or an expiring certificate. Scans keep it up to
 * date: seen again it stays open, gone it's resolved, and someone can ignore it with a reason.
 *
 * @property int $id
 * @property string $project_id
 * @property string $source one of SOURCES
 * @property string $scope what the scan that found it looked at, e.g. website:12, so a rescan resolves what's gone
 * @property string $fingerprint stable identity within the project
 * @property string $severity one of SEVERITIES
 * @property string $title
 * @property string|null $detail
 * @property string|null $subject what it's about, e.g. a website or package name
 * @property string|null $url where to read more
 * @property string|null $fix how to fix it
 * @property array<string, mixed>|null $data
 * @property string $status open, resolved or ignored
 * @property string|null $ignored_reason
 * @property string|null $ignored_by
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
final class SecurityFinding extends Model
{
    /**
     * The severities, most serious first, with their labels and how much each open finding takes off the score.
     *
     * @var array<string, array{label: string, weight: int, tone: string}>
     */
    public const SEVERITIES = [
        'critical' => ['label' => 'Critical', 'weight' => 25, 'tone' => 'danger'],
        'high' => ['label' => 'High', 'weight' => 10, 'tone' => 'danger'],
        'medium' => ['label' => 'Medium', 'weight' => 3, 'tone' => 'warning'],
        'low' => ['label' => 'Low', 'weight' => 1, 'tone' => 'info'],
        'info' => ['label' => 'Info', 'weight' => 0, 'tone' => 'neutral'],
    ];

    /**
     * Where findings come from, with their labels.
     *
     * @var array<string, string>
     */
    public const SOURCES = [
        'dependencies' => 'Dependencies',
        'secrets' => 'Secrets',
        'servers' => 'Servers',
        'domains' => 'Domains',
        'attacks' => 'Attacks',
        'access' => 'Access',
    ];

    /**
     * The attributes that can't be mass assigned: all of them; findings are written with forceFill.
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
        return ['data' => 'array', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    /**
     * Get the project the finding belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the severity's label.
     *
     * @return string
     */
    public function severityLabel(): string
    {
        return __(self::SEVERITIES[$this->severity]['label'] ?? ucfirst($this->severity));
    }

    /**
     * Get the badge tone for the severity.
     *
     * @return string
     */
    public function tone(): string
    {
        return self::SEVERITIES[$this->severity]['tone'] ?? 'neutral';
    }

    /**
     * Get the stable identity of a finding within its project, from its source, scope and key.
     *
     * @param  string  $source
     * @param  string  $scope
     * @param  string  $key
     * @return string
     */
    public static function fingerprint(string $source, string $scope, string $key): string
    {
        return hash('sha256', $source.'|'.$scope.'|'.$key);
    }
}
