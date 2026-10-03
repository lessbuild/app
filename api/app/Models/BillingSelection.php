<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SelectionKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $account_id
 * @property string $service
 * @property SelectionKind $kind
 * @property string $item_key
 * @property int $quantity
 * @property string|null $stripe_item_id
 * @property string|null $legacy_price_id
 * @property int|null $spend_cap_cents the monthly cap on pay-as-you-go spend for a usage selection; null for none
 * @property Carbon|null $ends_at
 */
class BillingSelection extends Model
{
    use HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * Reads `kind` as a SelectionKind (tier or add-on).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => SelectionKind::class, 'quantity' => 'integer', 'spend_cap_cents' => 'integer', 'ends_at' => 'datetime'];
    }
}
