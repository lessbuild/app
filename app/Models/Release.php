<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $project_id
 * @property string|null $service
 * @property string|null $service_namespace
 * @property string $version
 * @property string $service_hash
 * @property string $version_hash
 * @property CarbonImmutable|null $first_seen_at
 * @property CarbonImmutable|null $last_seen_at
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TelemetryEvent> $telemetryEvents
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Deployment> $deployments
 */
#[Fillable(['project_id', 'service', 'service_namespace', 'version', 'service_hash', 'version_hash', 'first_seen_at', 'last_seen_at'])]
#[Hidden(['service_hash', 'version_hash'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(ReleaseFactory::class)]
final class Release extends Model
{
    /** @use HasFactory<ReleaseFactory> */
    use HasFactory;

    /**
     * Limits a query to releases of the account's projects.
     *
     * @param  Builder<Release>  $query
     * @param  Account  $account
     * @return void
     */
    #[Scope]
    protected function forAccount(Builder $query, Account $account): void
    {
        $query->whereIn('project_id', $account->projects()->select('id'));
    }

    /**
     * The project the release belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Events reported by this release.
     *
     * @return HasMany<TelemetryEvent, $this>
     */
    public function telemetryEvents(): HasMany
    {
        return $this->hasMany(TelemetryEvent::class);
    }

    /**
     * When and where the release was deployed.
     *
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * The release's service as people read it: "namespace / service", or "Unspecified service".
     *
     * @return string
     */
    public function serviceLabel(): string
    {
        $name = $this->service ?? 'Unspecified service';

        return $this->service_namespace === null ? $name : $this->service_namespace.' / '.$name;
    }

    /**
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }
}
