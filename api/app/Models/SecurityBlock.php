<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An address Security blocked in a server's firewall for attacking it, until it expires or someone lifts it.
 *
 * @property int $id
 * @property string $project_id
 * @property int $server_id
 * @property string $ip
 * @property string $reason one of REASONS' keys
 * @property int $hits how many matching requests triggered it
 * @property string|null $detail
 * @property Carbon $expires_at
 * @property Carbon|null $lifted_at
 * @property string|null $lifted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
final class SecurityBlock extends Model
{
    /**
     * Why addresses are blocked, with their labels.
     *
     * @var array<string, string>
     */
    public const REASONS = ['brute-force' => 'Repeated failed sign-ins', 'scanner' => 'Probing for vulnerabilities', 'flood' => 'Flooding the site with requests'];

    /**
     * The attributes that can't be mass assigned: all of them; blocks are written with forceFill.
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
        return ['hits' => 'integer', 'expires_at' => 'datetime', 'lifted_at' => 'datetime'];
    }

    /**
     * Get the server the block is on.
     *
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
