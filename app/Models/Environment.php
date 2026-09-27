<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnvironmentKind;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string $slug
 * @property EnvironmentKind $kind
 * @property-read Project $project
 */
class Environment extends Model
{
    use HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => EnvironmentKind::class];
    }

    /** @param Builder<Environment> $query */
    #[Scope]
    protected function forAccount(Builder $query, Account $account): void
    {
        $query->whereIn('project_id', Project::query()->where('account_id', $account->id)->select('id'));
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
