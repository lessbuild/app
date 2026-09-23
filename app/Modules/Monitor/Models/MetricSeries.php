<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\MetricSeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['environment_id', 'identity_hash', 'source', 'name', 'resource_label', 'unit', 'kind', 'temporality', 'monotonic', 'descriptor', 'first_received_at', 'last_received_at'])]
#[Hidden(['identity_hash'])]
#[Table(name: 'metric_series', dateFormat: 'Y-m-d H:i:s.u')]
class MetricSeries extends Model
{
    /** @use HasFactory<MetricSeriesFactory> */
    use HasFactory;

    /** @param Builder<MetricSeries> $query */
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

    /** @return HasMany<MetricSample, $this> */
    public function samples(): HasMany
    {
        return $this->hasMany(MetricSample::class);
    }

    public function supportsRate(): bool
    {
        return $this->kind === 'sum' && $this->monotonic && in_array($this->temporality, ['cumulative', 'delta'], true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['descriptor' => 'array', 'monotonic' => 'boolean', 'first_received_at' => 'immutable_datetime', 'last_received_at' => 'immutable_datetime'];
    }
}
