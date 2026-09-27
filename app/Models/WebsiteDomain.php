<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A hostname a website answers on: its primary domain, an alias, or a redirect. With a Cloudflare provider, its A/AAAA record
 * is kept pointing at the website's server.
 *
 * @property int $id
 * @property int $website_id
 * @property string|null $created_by
 * @property int|null $dns_provider_id
 * @property string $hostname
 * @property string $type primary, alias or redirect
 * @property string|null $redirect_url
 * @property bool $is_temporary
 * @property string|null $dns_record_id zone ID and record ID
 * @property string $dns_status pending, active or error
 * @property string $ssl_status pending, active, expiring, expired or error
 * @property CarbonImmutable|null $certificate_expires_at
 * @property CarbonImmutable|null $last_checked_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Website $website
 * @property-read Provider|null $dnsProvider
 */
#[Hidden(['dns_record_id'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class WebsiteDomain extends Model
{
    public const TYPES = ['primary', 'alias', 'redirect'];

    /**
     * The website the domain serves.
     *
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * The DNS provider its records are managed through (`dns_provider_id`), if any.
     *
     * @return BelongsTo<Provider, $this>
     */
    public function dnsProvider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'dns_provider_id');
    }

    /**
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_temporary' => 'boolean', 'certificate_expires_at' => 'immutable_datetime', 'last_checked_at' => 'immutable_datetime'];
    }
}
