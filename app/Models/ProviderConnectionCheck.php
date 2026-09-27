<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One connection check of a provider. The last 100 per provider are kept.
 *
 * @property int $id
 * @property int $provider_id
 * @property bool $successful
 * @property string $source manual or automatic
 * @property string $provider_type
 * @property int|null $http_status
 * @property int $duration_ms
 * @property string|null $endpoint
 * @property string|null $error
 * @property CarbonImmutable $checked_at
 * @property-read Provider $provider
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ProviderConnectionCheck extends Model
{
    public const KEEP = 100;

    public $timestamps = false;

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['successful' => 'boolean', 'http_status' => 'integer', 'duration_ms' => 'integer', 'checked_at' => 'immutable_datetime'];
    }
}
