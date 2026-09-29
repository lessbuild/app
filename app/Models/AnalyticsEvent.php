<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property string $event_id
 * @property string $type
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 * @property string $path
 * @property string|null $referrer_host
 * @property string|null $visitor_hash
 * @property string|null $session_id
 * @property array<string, mixed>|null $properties
 */
class AnalyticsEvent extends Model
{
    /**
     * Stored in `analytics_events`.
     *
     * @var string|null
     */
    protected $table = 'analytics_events';

    /**
     * Written only by collection, from normalised events.
     *
     * @var list<string>
     */
    protected $fillable = ['site_id', 'ingestion_batch_id', 'event_id', 'type', 'occurred_at', 'received_at', 'path', 'referrer_host', 'utm_source', 'utm_medium', 'utm_campaign', 'device_category', 'browser', 'operating_system', 'visitor_hash', 'session_id', 'properties'];

    /**
     * Get the attributes that should be cast.
     *
     * Reads `properties` as JSON.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'received_at' => 'datetime', 'properties' => 'array'];
    }

    /**
     * Get the site that sent the event.
     *
     * @return BelongsTo<AnalyticsSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSite::class, 'site_id');
    }

    /**
     * Get the batch it arrived in; reports only count events whose batch was processed.
     *
     * @return BelongsTo<AnalyticsIngestionBatch, $this>
     */
    public function ingestionBatch(): BelongsTo
    {
        return $this->belongsTo(AnalyticsIngestionBatch::class, 'ingestion_batch_id');
    }

    /**
     * Limit a query to events that count in reports: those collected before batching existed, those in a processed
     * batch, and, while it's being processed, the given batch.
     *
     * @param  Builder<self>  $query
     * @param  AnalyticsIngestionBatch|null  $batch
     * @return void
     */
    #[Scope]
    protected function countable(Builder $query, ?AnalyticsIngestionBatch $batch = null): void
    {
        $query->where(function (Builder $query) use ($batch): void {
            $query->whereNull('ingestion_batch_id')
                ->orWhereHas('ingestionBatch', fn (Builder $batchQuery) => $batchQuery->where('status', 'processed'));
            if ($batch !== null) {
                $query->orWhere('ingestion_batch_id', $batch->id);
            }
        });
    }

    /**
     * Limit a query to events matching a report's filters: page, source (campaign source or referring site),
     * campaign and device. Empty filters are ignored.
     *
     * @param  Builder<self>  $query
     * @param  array<string, string|null>  $filters
     * @return void
     */
    #[Scope]
    protected function matchingReportFilters(Builder $query, array $filters): void
    {
        $query->when($filters['path'] ?? null, fn (Builder $query, string $path) => $query->where('path', $path))
            ->when($filters['source'] ?? null, fn (Builder $query, string $source) => $query->where(function (Builder $query) use ($source): void {
                $query->where('utm_source', $source)->orWhere('referrer_host', $source);
            }))
            ->when($filters['campaign'] ?? null, fn (Builder $query, string $campaign) => $query->where('utm_campaign', $campaign))
            ->when($filters['device'] ?? null, fn (Builder $query, string $device) => $query->where('device_category', $device));
    }

    /**
     * Identify whose visit the event belongs to: its session, else its daily visitor hash, else the event alone, so
     * events without either never merge with someone else's.
     *
     * @return string
     */
    public function visitorIdentity(): string
    {
        return $this->session_id ?: $this->visitor_hash ?: 'anonymous-'.$this->event_id;
    }
}
