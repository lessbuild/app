<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An earlier value of a variable (encrypted), for the record of who changed it when.
 *
 * @property int $id
 * @property int $environment_variable_id
 * @property string|null $created_by
 * @property int $version
 * @property string $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['value'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class EnvironmentVariableVersion extends Model
{
    /**
     * Encrypts `value`.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'encrypted', 'version' => 'integer'];
    }
}
