<?php

declare(strict_types=1);

namespace App\Domain\Projects\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A service switched on for a project.
 *
 * @property string $id
 * @property string $project_id
 * @property string $service
 * @property string|null $enabled_by_id
 * @property Carbon|null $created_at
 */
class EnabledService extends Model
{
    use HasUlids;

    protected $table = 'project_services';

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
