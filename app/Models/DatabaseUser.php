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
 * An extra MySQL login on a website's database (for a person or a tool), with read, write or full access and an optional
 * expiry. The password is generated, shown once and stored encrypted.
 *
 * @property int $id
 * @property int $website_id
 * @property string|null $created_by
 * @property string $username
 * @property string $password
 * @property string $privilege read, write or admin
 * @property string $status pending, active, removing or failed
 * @property string|null $error
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Website $website
 */
#[Hidden(['password'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class DatabaseUser extends Model
{
    public const PRIVILEGES = ['read' => 'Read only', 'write' => 'Read and write', 'admin' => 'Full access'];

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'encrypted', 'expires_at' => 'immutable_datetime', 'applied_at' => 'immutable_datetime'];
    }
}
