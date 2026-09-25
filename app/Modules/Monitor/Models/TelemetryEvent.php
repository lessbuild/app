<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\TelemetryEventFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use App\Modules\Monitor\Models\Concerns\HasProjectVisibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'environment_id',
    'dedupe_key',
    'trace_id',
    'span_id',
    'parent_span_id',
    'type',
    'severity',
    'name',
    'route',
    'service',
    'status_code',
    'duration_ms',
    'attributes',
    'payload',
    'occurred_at',
    'timestamp_unix_nano',
    'end_timestamp_unix_nano',
])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class TelemetryEvent extends Model
{
    /** @use HasFactory<TelemetryEventFactory> */
    use HasFactory;

    use HasProjectVisibility;

    /** @param Builder<TelemetryEvent> $query */
    #[Scope]
    protected function forWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->whereHas('environment.application', fn (Builder $application) => $application->whereBelongsTo($workspace));
    }

    /** @param Builder<TelemetryEvent> $query */
    #[Scope]
    protected function summary(Builder $query): void
    {
        $signal = $query->getQuery()->getGrammar()->wrap('payload->signal');
        $query->select([
            'id', 'environment_id', 'trace_id', 'span_id', 'parent_span_id', 'type', 'severity',
            'name', 'route', 'service', 'status_code', 'duration_ms', 'occurred_at', 'created_at',
            'timestamp_unix_nano', 'end_timestamp_unix_nano',
        ])->selectRaw("CASE {$signal} WHEN 'traces' THEN 'traces' WHEN 'logs' THEN 'logs' WHEN 'metrics' THEN 'metrics' ELSE NULL END AS source_signal");
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** @return BelongsTo<Release, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'duration_ms' => 'float',
            'attributes' => 'array',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
