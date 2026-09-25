<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\ApplicationFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use App\Modules\Monitor\Models\Concerns\HasProjectVisibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'framework', 'framework_version', 'accent'])]
class Application extends Model
{
    use HasProjectVisibility;

    public const ACCENTS = ['violet', 'sky', 'amber', 'emerald'];

    public const FRAMEWORK_PRESETS = ['Laravel', 'Node.js', 'Python', 'Go', 'Java', '.NET', 'Ruby', 'PHP', 'Other'];

    /** @use HasFactory<ApplicationFactory> */
    use HasFactory, SoftDeletes;

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<Environment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /** @return HasMany<Release, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }
}
