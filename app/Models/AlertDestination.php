<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertDestinationType;
use Database\Factories\AlertDestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $account_id
 * @property string $name
 * @property AlertDestinationType $type
 * @property bool $enabled
 * @property int $state_version
 * @property int $target_revision
 * @property string|null $recipient_user_id
 * @property string|null $endpoint_url
 * @property string|null $signing_secret
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property mixed $deleted_at
 * @property-read Account $account
 * @property-read User|null $recipient
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Monitor> $monitors
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AlertDelivery> $deliveries
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(AlertDestinationFactory::class)]
class AlertDestination extends Model
{
    /** @use HasFactory<AlertDestinationFactory> */
    use HasFactory, SoftDeletes;

    protected $hidden = ['endpoint_url', 'signing_secret'];

    /** @param Builder<AlertDestination> $query */
    #[Scope]
    protected function forAccount(Builder $query, Account $account): void
    {
        $query->whereBelongsTo($account);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /** @return BelongsToMany<Monitor, $this> */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class)->withPivot(['opened', 'recovered']);
    }

    /** @return BelongsToMany<AlertRule, $this> */
    public function alertRules(): BelongsToMany
    {
        return $this->belongsToMany(AlertRule::class)->withPivot(['opened', 'recovered']);
    }

    /** @return HasMany<AlertEscalation, $this> */
    public function escalations(): HasMany
    {
        return $this->hasMany(AlertEscalation::class);
    }

    /** @return HasMany<AlertDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }

    public function targetLabel(): string
    {
        return $this->type === AlertDestinationType::Email
            ? ($this->recipient->name ?? 'Recipient unavailable')
            : (parse_url($this->endpoint_url ?? '', PHP_URL_HOST) ?: 'Endpoint unavailable');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AlertDestinationType::class, 'enabled' => 'boolean',
            'state_version' => 'integer', 'target_revision' => 'integer',
            'endpoint_url' => 'encrypted', 'signing_secret' => 'encrypted',
        ];
    }
}
