<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone's SSH access to a server, as its deploy user with their own keys: pending while it's installed, active,
 * removing while it's taken off, or failed.
 *
 * @property int $id
 * @property int $server_id
 * @property string $user_id
 * @property string|null $granted_by
 * @property string $status
 * @property string|null $error
 * @property Carbon|null $applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read User $user
 */
final class ServerSshGrant extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; grants are written with forceFill.
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
        return ['applied_at' => 'datetime'];
    }

    /**
     * Get the server.
     *
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Get the person with access.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
