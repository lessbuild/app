<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Usage of one meter in one hour.
 *
 * @property string $id
 * @property string $account_id
 * @property string $meter
 * @property Carbon $period_start
 * @property int $quantity
 * @property int $reported_quantity
 */
class UsageRecord extends Model
{
    use HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['period_start' => 'datetime', 'quantity' => 'integer', 'reported_quantity' => 'integer'];
    }
}
