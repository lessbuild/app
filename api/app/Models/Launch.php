<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Setting up a whole repository in one go: the server, a website and environment per app, the repositories with
 * their settings, workers and resources, then the first deploys, API first. RunLaunch works through the STEPS and
 * keeps what it made in `result`, so a launch that stopped can carry on where it was.
 *
 * @property int $id
 * @property string $project_id
 * @property string|null $created_by
 * @property string $status one of STATUSES
 * @property string|null $step the step it's on, one of STEPS
 * @property array<string, mixed> $plan what was confirmed: the repository, the server and each app (see LaunchPlanner)
 * @property array<string, mixed>|null $result what it made so far: server_id, and per app its website, environment, repository and build
 * @property string|null $error why it stopped
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User|null $creator
 */
class Launch extends Model
{
    /**
     * Where a launch stands.
     *
     * @var list<string>
     */
    public const STATUSES = ['queued', 'running', 'waiting', 'done', 'failed'];

    /**
     * The steps, in order, with what each does.
     *
     * @var array<string, string>
     */
    public const STEPS = [
        'server' => 'Get the server ready',
        'websites' => 'Create the websites',
        'environments' => 'Set up the environments',
        'repositories' => 'Connect the repository',
        'deploys' => 'Deploy',
    ];

    /**
     * Get the project it sets up.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the person who started it (and as whom it runs).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether it's still going.
     *
     * @return bool
     */
    public function active(): bool
    {
        return in_array($this->status, ['queued', 'running', 'waiting'], true);
    }

    /**
     * Read the plan and result as arrays and the finish time as a date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['plan' => 'array', 'result' => 'array', 'finished_at' => 'datetime'];
    }
}
