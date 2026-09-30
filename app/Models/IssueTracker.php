<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where a project files tickets for Monitoring issues: a GitHub repository, a Linear team or a Jira Cloud project, with
 * the customer's own credentials (encrypted).
 *
 * @property int $id
 * @property string $project_id
 * @property string|null $created_by
 * @property string $kind github, linear or jira
 * @property string $name
 * @property array<string, string> $settings github: repository, token; linear: api_key, team_id; jira: site, email, token, project_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
final class IssueTracker extends Model
{
    /**
     * The kinds of tracker, with their names.
     *
     * @var array<string, string>
     */
    public const KINDS = ['github' => 'GitHub Issues', 'linear' => 'Linear', 'jira' => 'Jira'];

    /**
     * The attributes that can't be mass assigned: all of them; trackers are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * The attributes never serialised: the credentials.
     *
     * @var list<string>
     */
    protected $hidden = ['settings'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['settings' => 'encrypted:array'];
    }

    /**
     * Get the project the tracker belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Describe where tickets go, without secrets: the repository, Linear team or Jira project.
     *
     * @return string
     */
    public function destination(): string
    {
        return match ($this->kind) {
            'github' => (string) ($this->settings['repository'] ?? ''),
            'linear' => __('Linear team'),
            default => ($this->settings['project_key'] ?? '').' · '.parse_url((string) ($this->settings['site'] ?? ''), PHP_URL_HOST),
        };
    }
}
