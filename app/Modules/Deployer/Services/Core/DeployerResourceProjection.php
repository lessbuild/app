<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Services\Auth\ProductAuthentication;
use App\Modules\Deployer\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/** Filters nested collections without removing their independently authorized parent. */
final readonly class DeployerResourceProjection
{
    public function __construct(private ProductAuthentication $authentication, private DeployerProjectAccess $access) {}

    public function repositories(Builder|Relation $query, User $actor): Builder|Relation
    {
        if (! $this->authentication->usesCoreAuthority('deployer')) {
            return $query;
        }

        return $this->access->repositories($this->owned($query, $actor), $actor);
    }

    public function servers(Builder|Relation $query, User $actor): Builder|Relation
    {
        return ! $this->authentication->usesCoreAuthority('deployer')
            ? $query : $this->access->servers($this->owned($query, $actor), $actor);
    }

    public function websites(Builder|Relation $query, User $actor): Builder|Relation
    {
        return ! $this->authentication->usesCoreAuthority('deployer')
            ? $query : $this->access->websites($this->owned($query, $actor), $actor);
    }

    public function environments(Builder|Relation $query, User $actor): Builder|Relation
    {
        if (! $this->authentication->usesCoreAuthority('deployer')) {
            return $query;
        }
        if (! ($actor->currentOrganization?->permits($actor, 'view') ?? false)) {
            return $query->whereRaw('1 = 0');
        }

        return $this->access->environments($query->whereHas('project', fn (Builder $project) => $project
            ->where('organization_id', $actor->current_organization_id)), $actor);
    }

    public function builds(Builder|Relation $query, User $actor): Builder|Relation
    {
        if (! $this->authentication->usesCoreAuthority('deployer')) {
            return $query;
        }

        return $this->access->builds($query->whereHas('repository', fn (Builder $repository) => $this->owned($repository->withTrashed(), $actor)), $actor);
    }

    private function owned(Builder|Relation $query, User $actor): Builder|Relation
    {
        $organizationId = $actor->currentOrganization?->permits($actor, 'view') ? $actor->current_organization_id : null;
        $model = $query->getModel();

        return $query->where(function (Builder $owned) use ($organizationId, $actor, $model): void {
            $owned->where(fn (Builder $personal) => $personal->whereNull($model->qualifyColumn('organization_id'))
                ->where($model->qualifyColumn('user_id'), $actor->getKey()));
            if ($organizationId !== null) {
                $owned->orWhere($model->qualifyColumn('organization_id'), $organizationId);
            }
        });
    }
}
