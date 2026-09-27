<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TelemetryEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $environment_id
 * @property string $dedupe_key
 * @property string|null $trace_id
 * @property string|null $span_id
 * @property string|null $parent_span_id
 * @property string $type
 * @property string $severity
 * @property string|null $name
 * @property string|null $route
 * @property string|null $service
 * @property int|null $status_code
 * @property float|null $duration_ms
 * @property array<string, mixed>|null $attributes
 * @property array<string, mixed>|null $payload
 * @property Carbon $occurred_at
 * @property string|null $timestamp_unix_nano
 * @property string|null $end_timestamp_unix_nano
 * @property int|null $issue_id
 * @property int|null $release_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 * @property-read string|null $source_signal set by the summary() scope: the OTLP signal an event came from
 * @property-read Issue|null $issue
 * @property-read Release|null $release
 */
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
#[UseFactory(TelemetryEventFactory::class)]
final class TelemetryEvent extends Model
{
    /** @use HasFactory<TelemetryEventFactory> */
    use HasFactory;

    /** @param Builder<TelemetryEvent> $query */
    #[Scope]
    protected function forAccount(Builder $query, Account $account): void
    {
        $query->whereHas('environment.project', fn (Builder $project) => $project->whereBelongsTo($account));
    }

    /** @param Builder<TelemetryEvent> $query */
    #[Scope]
    protected function summary(Builder $query): void
    {
        $signal = DB::getDriverName() === 'pgsql' ? "payload->>'signal'" : "json_extract(payload, '$.signal')";
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
