<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Whether a member gets the daily issue digest for an account. Without a row, owners get it and others don't.
 *
 * @property int $id
 * @property string $account_id
 * @property string $user_id
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class IssueDigestPreference extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
