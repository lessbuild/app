<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Deploy\RepositoryPath;
use Carbon\CarbonImmutable;
use Database\Factories\BuildFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One deploy of a repository to its website: a release directory on the server, reported stage by stage through the
 * signed deployment callbacks. Deployer called these builds, and so does this app (Monitoring's "deployments" are markers).
 *
 * @property int $id
 * @property int $repository_id
 * @property int $website_id
 * @property string|null $environment_id
 * @property string|null $requested_by
 * @property string|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $rejected_by
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $approval_note
 * @property string $status see the STATUS_ constants
 * @property string $trigger_source manual, webhook, redeploy, rollback, scheduled, api or promotion
 * @property string|null $revision
 * @property string|null $git_ref the branch, tag or commit someone asked to deploy, resolved on the server; null for the repository's branch
 * @property string|null $commit_message
 * @property list<string>|null $changed_paths
 * @property string|null $operator_note
 * @property array<string, mixed>|null $environment_payload the environment's settings, variables and processes when it was queued (encrypted)
 * @property int $setup_stage
 * @property string|null $release_name
 * @property string|null $release_path
 * @property int|null $remote_process_id
 * @property string|null $remote_process_path
 * @property string|null $log the tail of the deployment log (encrypted)
 * @property string|null $failure_message
 * @property int|null $redeployed_from_build_id
 * @property int|null $rolled_back_from_build_id
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $last_heartbeat_at
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $automatic_rollback_build_id the rollback started because this one failed after going live
 * @property int|null $observation_minutes how long the website's health is watched after it goes live
 * @property string|null $observation_status observing, passed or failed
 * @property CarbonImmutable|null $observation_deadline_at
 * @property string|null $observation_error
 * @property int|null $promoted_from_build_id the build in an earlier environment whose commit this one ships
 * @property string|null $promotion_note
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Repository $repository
 * @property-read Website $website
 * @property-read Environment|null $environment
 * @property-read User|null $requester
 * @property-read User|null $approver
 * @property-read Build|null $rolledBackFrom
 * @property-read Build|null $redeployedFrom
 * @property-read Build|null $promotedFrom
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Build> $promotions
 */
#[Hidden(['environment_payload', 'log'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(BuildFactory::class)]
class Build extends Model
{
    /** @use HasFactory<BuildFactory> */
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DEPLOYING = 'deploying';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    /** A website has at most one build in these states. */
    public const ACTIVE = [self::STATUS_QUEUED, self::STATUS_AWAITING_APPROVAL, self::STATUS_DEPLOYING, self::STATUS_RUNNING];

    public const FINISHED = [self::STATUS_REJECTED, self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELED];

    /**
     * Get the repository deployed, including disconnected ones so old deploys still read.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class)->withTrashed();
    }

    /**
     * Get the website deployed to, including deleted ones.
     *
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class)->withTrashed();
    }

    /**
     * Get the environment the deploy was for.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the person who asked for the deploy (`requested_by`); null for pushes and automation.
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Get the person who approved the deploy, for environments that require approval (`approved_by`).
     *
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the deploy this one rolled back (`rolled_back_from_build_id`).
     *
     * @return BelongsTo<Build, $this>
     */
    public function rolledBackFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rolled_back_from_build_id');
    }

    /**
     * Get the deploy this one repeated (`redeployed_from_build_id`).
     *
     * @return BelongsTo<Build, $this>
     */
    public function redeployedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'redeployed_from_build_id');
    }

    /**
     * Get the deploy in a lower environment this one promoted (`promoted_from_build_id`).
     *
     * @return BelongsTo<Build, $this>
     */
    public function promotedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'promoted_from_build_id');
    }

    /**
     * Get the deploys that promoted this one to higher environments.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Build, $this>
     */
    public function promotions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'promoted_from_build_id');
    }

    /**
     * Determine whether the deploy is still queued, waiting for approval or running.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /**
     * Get the repository subdirectory this build deploys, as it was when queued.
     *
     * @return string
     */
    public function deploymentRoot(): string
    {
        $payload = $this->environment_payload;

        return RepositoryPath::normalizeRoot(is_array($payload) && array_key_exists('repository_root', $payload) ? $payload['repository_root'] : $this->repository->deployment_root);
    }

    /**
     * Get a path under the website's directory for a phase (`setup` while building, `current` once live), inside the
     * deployed subdirectory.
     *
     * @param  string  $phase
     * @return string
     */
    public function deploymentPath(string $phase): string
    {
        return RepositoryPath::withRoot("/var/www/{$this->website->deployment_slug}/{$phase}", $this->deploymentRoot());
    }

    /**
     * Get the release directory name: the one recorded when it started, else one made from its creation time and ID.
     *
     * @return string
     */
    public function releaseIdentifier(): string
    {
        return $this->release_name ?? sprintf('%s-build-%d', ($this->created_at ?? now())->utc()->format('YmdHis'), $this->id);
    }

    /**
     * Get the first 12 characters of the commit, for display.
     *
     * @return string|null
     */
    public function shortRevision(): ?string
    {
        return $this->revision === null ? null : substr($this->revision, 0, 12);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts the environment payload (it holds variables and secrets) and the log, and reads `changed_paths` as
     * JSON.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment_payload' => 'encrypted:array', 'log' => 'encrypted', 'changed_paths' => 'array', 'setup_stage' => 'integer',
            'remote_process_id' => 'integer', 'approved_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'last_heartbeat_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime', 'observation_minutes' => 'integer', 'observation_deadline_at' => 'immutable_datetime',
        ];
    }
}
