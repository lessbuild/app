<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'status', 'event_count', 'last_seen_at'])]
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use HasFactory, SoftDeletes;

    /** @return HasMany<IngestToken, $this> */
    public function ingestTokens(): HasMany
    {
        return $this->hasMany(IngestToken::class);
    }

    /** @param Builder<Environment> $query */
    #[Scope]
    protected function forWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->whereHas('application', fn (Builder $application) => $application->whereBelongsTo($workspace));
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return HasMany<TelemetryEvent, $this>
     */
    public function telemetryEvents(): HasMany
    {
        return $this->hasMany(TelemetryEvent::class);
    }

    /** @return HasMany<IngestReceipt, $this> */
    public function ingestReceipts(): HasMany
    {
        return $this->hasMany(IngestReceipt::class);
    }

    /** @return HasMany<Deployment, $this> */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_count' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }
}
