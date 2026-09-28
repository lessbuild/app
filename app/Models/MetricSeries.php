<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MetricSeriesFactory;
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
 * @property string $environment_id
 * @property string $identity_hash
 * @property string $source
 * @property string $name
 * @property string $resource_label
 * @property string $unit
 * @property string $kind
 * @property string|null $temporality
 * @property bool $monotonic
 * @property array<string, mixed> $descriptor
 * @property CarbonImmutable $first_received_at
 * @property CarbonImmutable $last_received_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MetricSample> $samples
 */
#[Fillable(['environment_id', 'identity_hash', 'source', 'name', 'resource_label', 'unit', 'kind', 'temporality', 'monotonic', 'descriptor', 'first_received_at', 'last_received_at'])]
#[Hidden(['identity_hash'])]
#[Table(name: 'metric_series', dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(MetricSeriesFactory::class)]
final class MetricSeries extends Model
{
    /** @use HasFactory<MetricSeriesFactory> */
    use HasFactory;

    /**
     * Limit a query to series in the account's environments.
     *
     * @param  Builder<MetricSeries>  $query
     * @param  Account  $account
     * @return void
     */
    #[Scope]
    protected function forAccount(Builder $query, Account $account): void
    {
        $query->whereIn('environment_id', Environment::forAccount($account)->select('id'));
    }

    /**
     * Get the environment that reports the series.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the series's data points.
     *
     * @return HasMany<MetricSample, $this>
     */
    public function samples(): HasMany
    {
        return $this->hasMany(MetricSample::class);
    }

    /**
     * Determine whether a per-second rate makes sense: only monotonic sums (counters) with a known temporality.
     *
     * @return bool
     */
    public function supportsRate(): bool
    {
        return $this->kind === 'sum' && $this->monotonic && in_array($this->temporality, ['cumulative', 'delta'], true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads `descriptor` (the OTLP metric's attributes and unit) as JSON.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['descriptor' => 'array', 'monotonic' => 'boolean', 'first_received_at' => 'immutable_datetime', 'last_received_at' => 'immutable_datetime'];
    }
}
