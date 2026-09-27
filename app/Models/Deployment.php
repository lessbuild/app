<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $environment_id
 * @property int $release_id
 * @property string|null $actor_id
 * @property int|null $ingest_token_id
 * @property string $deployment_key
 * @property string $payload_hash
 * @property string $source
 * @property string|null $commit_sha
 * @property string|null $note
 * @property CarbonImmutable $deployed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 * @property-read Release $release
 * @property-read User|null $actor
 * @property-read IngestToken|null $ingestToken
 */
#[Fillable(['environment_id', 'release_id', 'actor_id', 'ingest_token_id', 'deployment_key', 'payload_hash', 'source', 'commit_sha', 'note', 'deployed_at'])]
#[Hidden(['payload_hash'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(DeploymentFactory::class)]
final class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory;

    /**
     * Limits a query to deployments in the account's environments whose release also belongs to the account.
     *
     * @param  Builder<Deployment>  $query
     */
    #[Scope]
    protected function forAccount(Builder $query, Account $account): void
    {
        $query->whereHas('environment.project', fn (Builder $project) => $project->whereBelongsTo($account))
            ->whereHas('release', fn (Builder $release) => $release->forAccount($account));
    }

    /**
     * The environment deployed to.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * The release deployed.
     *
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * Who reported the deployment (`actor_id`), when a person did.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The ingest token that reported it, when a pipeline did.
     *
     * @return BelongsTo<IngestToken, $this>
     */
    public function ingestToken(): BelongsTo
    {
        return $this->belongsTo(IngestToken::class);
    }

    /**
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['deployed_at' => 'immutable_datetime'];
    }
}
