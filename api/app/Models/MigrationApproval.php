<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Someone's approval of a revision's destructive database migrations in an environment, so its deploys run them.
 *
 * @property int $id
 * @property string $environment_id
 * @property string $revision
 * @property string|null $approved_by
 * @property string $statements what was approved
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MigrationApproval extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; approvals are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];
}
