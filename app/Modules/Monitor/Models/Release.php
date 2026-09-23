<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\ReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['application_id', 'service', 'service_namespace', 'version', 'service_hash', 'version_hash', 'first_seen_at', 'last_seen_at'])]
#[Hidden(['service_hash', 'version_hash'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class Release extends Model
{
    /** @use HasFactory<ReleaseFactory> */
    use HasFactory;

    /** @param Builder<Release> $query */
    #[Scope]
    protected function forWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->whereIn('application_id', $workspace->applications()->select('id'));
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return HasMany<TelemetryEvent, $this> */
    public function telemetryEvents(): HasMany
    {
        return $this->hasMany(TelemetryEvent::class);
    }

    /** @return HasMany<Deployment, $this> */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function serviceLabel(): string
    {
        $name = $this->service ?? 'Unspecified service';

        return $this->service_namespace === null ? $name : $this->service_namespace.' / '.$name;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }
}
