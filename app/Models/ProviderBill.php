<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a cloud provider charged for a month: the invoice once the month has closed (final), or the usage so far.
 *
 * @property int $id
 * @property int $provider_id
 * @property string $period YYYY-MM
 * @property float $amount
 * @property string $currency
 * @property bool $final whether it's the month's invoice rather than usage so far
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Provider $provider
 */
final class ProviderBill extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; bills are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'float', 'final' => 'boolean'];
    }

    /**
     * Get the provider billed.
     *
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
