<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\DeploymentFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use App\Modules\Monitor\Models\Concerns\HasProjectVisibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['environment_id', 'release_id', 'actor_id', 'ingest_token_id', 'deployment_key', 'payload_hash', 'source', 'commit_sha', 'note', 'deployed_at'])]
#[Hidden(['payload_hash'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory;

    use HasProjectVisibility;

    /** @param Builder<Deployment> $query */
    #[Scope]
    protected function forWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->whereHas('environment.application', fn (Builder $application) => $application->whereBelongsTo($workspace))
            ->whereHas('release', fn (Builder $release) => $release->forWorkspace($workspace));
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<Release, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<IngestToken, $this> */
    public function ingestToken(): BelongsTo
    {
        return $this->belongsTo(IngestToken::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['deployed_at' => 'immutable_datetime'];
    }
}
