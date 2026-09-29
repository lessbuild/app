<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
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
 * @property string|null $custom_domain the customer's own hostname for the page (ASCII)
 * @property string|null $custom_domain_token the value its TXT record must carry
 * @property CarbonImmutable|null $custom_domain_verified_at when the TXT record was found; the domain serves the page from then on
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

    /**
     * Get the name of the TXT record that proves the customer controls the custom domain.
     *
     * @return string|null
     */
    public function domainRecordName(): ?string
    {
        return $this->custom_domain === null ? null : Domain::RECORD_PREFIX.'.'.$this->custom_domain;
    }

    /**
     * Get the value the custom domain's TXT record must have.
     *
     * @return string|null
     */
    public function domainRecordValue(): ?string
    {
        return $this->custom_domain_token === null ? null : 'buildpusher-status='.$this->custom_domain_token;
    }

    /**
     * Determine whether the page is served on its custom domain.
     *
     * @return bool
     */
    public function servesCustomDomain(): bool
    {
        return $this->published && $this->custom_domain !== null && $this->custom_domain_verified_at !== null;
    }

    /**
     * Get the page's public address: its custom domain once verified, otherwise its /status/{slug} URL.
     *
     * @return string
     */
    public function publicUrl(): string
    {
        return $this->servesCustomDomain() ? 'https://'.$this->custom_domain.'/' : route('status.show', $this->slug);
    }

    /**
     * Get the account the page belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the monitors it shows, in order.
     *
     * @return HasMany<StatusPageComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(StatusPageComponent::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Get the incident and maintenance updates posted to the page.
     *
     * @return HasMany<StatusUpdate, $this>
     */
    public function updates(): HasMany
    {
        return $this->hasMany(StatusUpdate::class);
    }

    /**
     * Get the people subscribed to the page's updates.
     *
     * @return HasMany<StatusSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(StatusSubscription::class);
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
        return ['published' => 'boolean', 'custom_domain_verified_at' => 'immutable_datetime'];
    }
}
