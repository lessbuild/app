<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An address that receives the account's events as signed JSON, for people's own automation.
 *
 * @property int $id
 * @property string $account_id
 * @property string|null $created_by
 * @property string $url
 * @property string|null $description
 * @property list<string> $events event names, or ["*"] for all of them
 * @property string $signing_secret
 * @property bool $enabled
 * @property int $failure_count deliveries that failed in a row
 * @property string|null $last_error
 * @property Carbon|null $last_delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
final class WebhookEndpoint extends Model
{
    /**
     * How many deliveries in a row may fail (after their retries) before the endpoint is paused.
     *
     * @var int
     */
    public const MAX_FAILURES = 20;

    /**
     * The attributes that can't be mass assigned: all of them; endpoints are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * The attributes hidden when serialised.
     *
     * @var list<string>
     */
    protected $hidden = ['signing_secret'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['events' => 'array', 'signing_secret' => 'encrypted', 'enabled' => 'boolean', 'last_delivered_at' => 'datetime'];
    }

    /**
     * Get the account it belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get its deliveries.
     *
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * Determine whether the endpoint wants an event.
     *
     * @param  string  $event
     * @return bool
     */
    public function wants(string $event): bool
    {
        return in_array('*', $this->events, true) || in_array($event, $this->events, true);
    }
}
