<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ServiceLevelObjectiveFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $environment_id
 * @property string $name
 * @property string $indicator
 * @property string|null $service
 * @property string|null $route
 * @property float $target
 * @property int $window_days
 * @property float|null $latency_threshold_ms
 * @property int $status_min
 * @property int $status_max
 * @property bool $enabled
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property mixed $deleted_at
 * @property-read Environment $environment
 */
#[Fillable(['environment_id', 'name', 'indicator', 'service', 'route', 'target', 'window_days', 'latency_threshold_ms', 'status_min', 'status_max', 'enabled'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(ServiceLevelObjectiveFactory::class)]
final class ServiceLevelObjective extends Model
{
    /** @use HasFactory<ServiceLevelObjectiveFactory> */
    use HasFactory, SoftDeletes;

    /** @param Builder<ServiceLevelObjective> $query */
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
