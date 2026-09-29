<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Slack channel or signed webhook that gets a status page's updates. It's active once the confirmation post was
 * accepted, and ends after five failed deliveries in a row.
 *
 * @property int $id
 * @property int $status_page_id
 * @property string $type slack or webhook
 * @property string $endpoint_url encrypted
 * @property string $endpoint_hash
 * @property string|null $signing_secret encrypted; webhooks only
 * @property string $unsubscribe_token
 * @property Carbon|null $verified_at
 * @property int $failure_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StatusPage $statusPage
 */
class StatusWebhookSubscription extends Model
{
    public const MAX_FAILURES = 5;

    /**
     * Get the page it follows.
     *
     * @return BelongsTo<StatusPage, $this>
     */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts the endpoint and secret.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['endpoint_url' => 'encrypted', 'signing_secret' => 'encrypted', 'verified_at' => 'datetime'];
    }
}
