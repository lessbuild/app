<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StatusPageFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A public page (`/status/{slug}`) showing some of an account's monitors and the team's status updates.
 *
 * @property int $id
 * @property string $account_id
 * @property string|null $created_by
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $published
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read Collection<int, StatusPageComponent> $components
 * @property-read Collection<int, StatusUpdate> $updates
 * @property-read Collection<int, StatusSubscription> $subscriptions
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(StatusPageFactory::class)]
class StatusPage extends Model
{
    /** @use HasFactory<StatusPageFactory> */
    use HasFactory;

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return HasMany<StatusPageComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(StatusPageComponent::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<StatusUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(StatusUpdate::class);
    }

    /** @return HasMany<StatusSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(StatusSubscription::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['published' => 'boolean'];
    }
}
