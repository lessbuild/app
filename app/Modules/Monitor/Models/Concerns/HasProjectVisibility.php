<?php

namespace App\Modules\Monitor\Models\Concerns;

use App\Core\Enums\ProjectResourceAccessPurpose;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Core\MonitorProjectAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

trait HasProjectVisibility
{
    /** Browser/user queries opt in; ingestion, workers and quota accounting remain workspace-wide. */
    #[Scope]
    protected function visibleTo(Builder $query, Authenticatable $principal, Workspace $workspace, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive): void
    {
        app(MonitorProjectAccess::class)->constrain($query, $principal, $workspace, $purpose);
    }
}
