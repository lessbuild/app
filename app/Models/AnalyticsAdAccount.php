<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An ad account an Analytics site reads its daily campaign spend from.
 *
 * @property int $id
 * @property int $site_id
 * @property string $platform google or meta
 * @property string $account_id the platform's ID for the account
 * @property string $name
 * @property string $source the utm_source its spend is filed under, such as google or facebook
 * @property string $credential the refresh or long-lived token (encrypted)
 * @property string|null $connected_by
 * @property Carbon|null $synced_at
 * @property string|null $error why the last sync failed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AnalyticsSite $site
 */
final class AnalyticsAdAccount extends Model
{
    /**
     * The platforms an account can come from, with their names and the source their spend is filed under.
     *
     * @var array<string, array{name: string, source: string}>
     */
    public const array PLATFORMS = [
        'google' => ['name' => 'Google Ads', 'source' => 'google'],
        'meta' => ['name' => 'Meta Ads', 'source' => 'facebook'],
    ];

    /**
     * The attributes that can't be mass assigned: all of them; accounts are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * The attributes kept out of arrays and JSON.
     *
     * @var list<string>
     */
    protected $hidden = ['credential'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['credential' => 'encrypted', 'synced_at' => 'datetime'];
    }

    /**
     * Get the site it feeds.
     *
     * @return BelongsTo<AnalyticsSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSite::class, 'site_id');
    }

    /**
     * Get the platform's name.
     *
     * @return string
     */
    public function platformName(): string
    {
        return self::PLATFORMS[$this->platform]['name'] ?? $this->platform;
    }
}
