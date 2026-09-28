<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MaintenanceWindowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $account_id
 * @property string|null $created_by
 * @property string $name
 * @property string|null $reason
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read User|null $creator
 */
#[Fillable(['account_id', 'created_by', 'name', 'reason', 'starts_at', 'ends_at'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(MaintenanceWindowFactory::class)]
class MaintenanceWindow extends Model
{
    /** @use HasFactory<MaintenanceWindowFactory> */
    use HasFactory;

    /**
     * Limit a query to the account's windows in effect at a moment.
     *
     * @param  Builder<MaintenanceWindow>  $query
     * @param  Account  $account
     * @param  CarbonImmutable  $at
     * @return void
     */
    #[Scope]
    protected function activeAt(Builder $query, Account $account, CarbonImmutable $at): void
    {
        $query->whereBelongsTo($account)->where('starts_at', '<=', $at)->where('ends_at', '>', $at);
    }

    /**
     * Get the account the window belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the person who scheduled the window (`created_by`).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }
}
