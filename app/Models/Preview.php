<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A pull request's own copy of an application: a website, a `preview` environment and a repository on the pull
 * request's branch, made from the source repository and kept until the pull request closes or the preview expires.
 * Its records stay afterwards as history, and a reopened pull request brings the same stack back.
 *
 * @property int $id
 * @property string $project_id
 * @property int $source_repository_id
 * @property string|null $source_environment_id
 * @property string|null $environment_id
 * @property int|null $website_id
 * @property int|null $repository_id
 * @property int $pull_request_number
 * @property string|null $title
 * @property string $source_branch
 * @property string $revision the pull request's head commit, which the preview deploys
 * @property string $status see the STATUS_ constants
 * @property string|null $url the preview's hostname
 * @property CarbonImmutable|null $initialized_at when a deploy with the initialisation command first succeeded
 * @property CarbonImmutable $last_activity_at the lifetime counts from here
 * @property CarbonImmutable|null $closed_at
 * @property string|null $cleanup_status see the CLEANUP_ constants; null until the preview is closed
 * @property string|null $cleanup_error
 * @property int $cleanup_attempts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Repository $sourceRepository
 * @property-read Environment|null $sourceEnvironment
 * @property-read Environment|null $environment
 * @property-read Website|null $website
 * @property-read Repository|null $repository
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PreviewSecretApproval> $secretApprovals
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class Preview extends Model
{
    /**
     * The preview's website is being set up.
     *
     * @var string
     */
    public const STATUS_PROVISIONING = 'provisioning';

    /**
     * The pull request's latest revision is deploying.
     *
     * @var string
     */
    public const STATUS_DEPLOYING = 'deploying';

    /**
     * The latest revision is live.
     *
     * @var string
     */
    public const STATUS_READY = 'ready';

    /**
     * The website or the latest revision's deploy failed.
     *
     * @var string
     */
    public const STATUS_FAILED = 'failed';

    /**
     * The pull request closed or the preview expired; its stack is being or has been removed.
     *
     * @var string
     */
    public const STATUS_CLOSED = 'closed';

    /**
     * Cleanup waits for a worker.
     *
     * @var string
     */
    public const CLEANUP_QUEUED = 'queued';

    /**
     * A worker is removing the stack from the server.
     *
     * @var string
     */
    public const CLEANUP_RUNNING = 'running';

    /**
     * The stack is gone from the server.
     *
     * @var string
     */
    public const CLEANUP_SUCCEEDED = 'succeeded';

    /**
     * Cleanup failed; `cleanup_error` says why, and it can be retried.
     *
     * @var string
     */
    public const CLEANUP_FAILED = 'failed';

    /**
     * Get the project the preview belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the repository whose pull request this is, which holds the preview settings.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function sourceRepository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'source_repository_id')->withTrashed();
    }

    /**
     * Get the environment the preview copies its runtime from and may borrow approved secrets from.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function sourceEnvironment(): BelongsTo
    {
        return $this->belongsTo(Environment::class, 'source_environment_id');
    }

    /**
     * Get the preview's own `preview` environment.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the preview's website, including after it's been deleted by cleanup.
     *
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class)->withTrashed();
    }

    /**
     * Get the preview's repository, which deploys the pull request's branch to its website.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class)->withTrashed();
    }

    /**
     * Get the secrets approvals given for its revisions.
     *
     * @return HasMany<PreviewSecretApproval, $this>
     */
    public function secretApprovals(): HasMany
    {
        return $this->hasMany(PreviewSecretApproval::class);
    }

    /**
     * Determine whether the preview is still open (not closed).
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_CLOSED;
    }

    /**
     * Get when the preview expires: its source repository's lifetime after the last activity.
     *
     * @return CarbonImmutable
     */
    public function expiresAt(): CarbonImmutable
    {
        return $this->last_activity_at->addHours($this->sourceRepository->preview_ttl_hours);
    }

    /**
     * Get the first twelve characters of the revision, as shown on pages.
     *
     * @return string
     */
    public function shortRevision(): string
    {
        return substr($this->revision, 0, 12);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads the timestamps as immutable dates.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pull_request_number' => 'integer', 'cleanup_attempts' => 'integer', 'initialized_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime',
        ];
    }
}
