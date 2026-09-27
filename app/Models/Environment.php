<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnvironmentKind;
use Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string $slug
 * @property EnvironmentKind $kind
 * @property int $telemetry_event_count events Monitoring has received for this environment
 * @property \Carbon\CarbonImmutable|null $telemetry_last_received_at
 * @property-read Project $project
 */
#[UseFactory(EnvironmentFactory::class)]
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => EnvironmentKind::class, 'telemetry_event_count' => 'integer', 'telemetry_last_received_at' => 'immutable_datetime'];
    }

    /** @param Builder<Environment> $query */
    #[Scope]
    protected function forAccount(Builder $query, Account $account): void
    {
        $query->whereIn('project_id', Project::query()->where('account_id', $account->id)->select('id'));
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<IngestToken, $this> */
    public function ingestTokens(): HasMany
    {
        return $this->hasMany(IngestToken::class);
    }

    /** @return HasMany<Deployment, $this> */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }
}
