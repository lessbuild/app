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

    /** @param Builder<MetricSeries> $query */
    #[Scope]
    protected function forAccount(Builder $query, Account $account): void
    {
        $query->whereIn('environment_id', Environment::forAccount($account)->select('id'));
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
