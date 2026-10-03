<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A person's named filter on a list page, private to them. `parameters` are the page's route parameters (such as the
 * project) and `query` the filters; both are limited to what the page allows (see App\Support\SavedViewPages).
 *
 * @property int $id
 * @property string $user_id
 * @property string|null $account_id
 * @property string $page
 * @property string $name
 * @property array<string, string> $parameters
 * @property array<string, string> $query
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class SavedView extends Model
{
    /**
     * The most views one person can save on one page.
     *
     * @var int
     */
    public const LIMIT = 20;

    /**
     * Get the attributes that should be cast.
     *
     * Reads the route parameters and filters as JSON maps.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['parameters' => 'array', 'query' => 'array'];
    }
}
