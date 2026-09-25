<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\ServiceLevelObjectiveFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use App\Modules\Monitor\Models\Concerns\HasProjectVisibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['environment_id', 'name', 'indicator', 'service', 'route', 'target', 'window_days', 'latency_threshold_ms', 'status_min', 'status_max', 'enabled'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ServiceLevelObjective extends Model
{
    /** @use HasFactory<ServiceLevelObjectiveFactory> */
    use HasFactory, SoftDeletes;

    use HasProjectVisibility;

    /** @param Builder<ServiceLevelObjective> $query */
    #[Scope]
    protected function forWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->whereIn('environment_id', Environment::forWorkspace($workspace)->select('id'));
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function indicatorLabel(): string
    {
        return $this->indicator === 'latency' ? 'Latency' : 'Availability';
    }

    public function scopeLabel(): string
    {
        return collect([$this->service, $this->route])->filter(fn (?string $value): bool => filled($value))->implode(' · ')
            ?: 'All request traffic';
    }

    public function windowLabel(): string
    {
        return $this->window_days.'-day rolling window';
    }

    public function isLatency(): bool
    {
        return $this->indicator === 'latency';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target' => 'float',
            'window_days' => 'integer',
            'latency_threshold_ms' => 'float',
            'status_min' => 'integer',
            'status_max' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}
