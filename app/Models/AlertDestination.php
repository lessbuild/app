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

    /**
     * The endpoint and signing secret never leave the server in serialised form.
     *
     * @var list<string>
     */
    protected $hidden = ['endpoint_url', 'signing_secret'];

    /**
     * Limit a query to the account's destinations.
     *
     * @param  Builder<AlertDestination>  $query
     * @param  Account  $account
     * @return void
     */
    #[Scope]
    protected function forAccount(Builder $query, Account $account): void
    {
        $query->whereBelongsTo($account);
    }

    /**
     * Get the account the destination belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the member an email destination sends to (`recipient_user_id`).
     *
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * Get the monitors that alert this destination.
     *
     * @return BelongsToMany<Monitor, $this>
     */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class)->withPivot(['opened', 'recovered']);
    }

    /**
     * Get the alert rules that alert this destination.
     *
     * @return BelongsToMany<AlertRule, $this>
     */
    public function alertRules(): BelongsToMany
    {
        return $this->belongsToMany(AlertRule::class)->withPivot(['opened', 'recovered']);
    }

    /**
     * Get the escalation steps that notify this destination.
     *
     * @return HasMany<AlertEscalation, $this>
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(AlertEscalation::class);
    }

    /**
     * Get the alerts sent to this destination.
     *
     * @return HasMany<AlertDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }

    /**
     * Describe where alerts go, safe to show: the recipient's name for email, or only the host of a webhook URL (the
     * full URL may contain a token).
     *
     * @return string
     */
    public function targetLabel(): string
    {
        return $this->type === AlertDestinationType::Email
            ? ($this->recipient->name ?? 'Recipient unavailable')
            : (parse_url($this->endpoint_url ?? '', PHP_URL_HOST) ?: 'Endpoint unavailable');
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts `endpoint_url` and `signing_secret`, and reads `type` as an AlertDestinationType.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AlertDestinationType::class, 'enabled' => 'boolean',
            'state_version' => 'integer', 'target_revision' => 'integer',
            'endpoint_url' => 'encrypted', 'signing_secret' => 'encrypted',
        ];
    }
}
