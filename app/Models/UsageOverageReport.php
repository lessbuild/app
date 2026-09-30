<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How much of a month's usage beyond the allowance has been reported to Stripe for one account and meter, so each
 * report sends only what's new.
 *
 * @property int $id
 * @property string $account_id
 * @property string $meter
 * @property Carbon $period_start the first day of the month
 * @property int $reported
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class UsageOverageReport extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; reports are written with forceFill.
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
        return ['period_start' => 'date', 'reported' => 'integer'];
    }
}
