<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A project someone pinned to the top of their projects list. Pins are personal.
 *
 * @property int $id
 * @property string $user_id
 * @property string $project_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ProjectPin extends Model {}
