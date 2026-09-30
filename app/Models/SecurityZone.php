<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Cloudflare zone's security settings as a project chose them: how suspicious a visitor must look to be challenged,
 * whether known bots are fought off, and whether every visitor gets a challenge while under attack.
 *
 * @property int $id
 * @property string $project_id
 * @property int $provider_id the Cloudflare connection
 * @property string $zone_id
 * @property string|null $zone_name
 * @property string $security_level one of LEVELS' keys
 * @property bool $bot_fight_mode
 * @property bool $under_attack
 * @property string|null $last_error
 * @property Carbon|null $applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Provider $provider
 */
final class SecurityZone extends Model
{
    /**
     * Cloudflare's security levels, with their labels.
     *
     * @var array<string, string>
     */
    public const LEVELS = ['essentially_off' => 'Essentially off', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

    /**
     * The attributes that can't be mass assigned: all of them; zones are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['bot_fight_mode' => 'boolean', 'under_attack' => 'boolean', 'applied_at' => 'datetime'];
    }

    /**
     * Get the Cloudflare connection used to change the zone.
     *
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
