<?php

namespace App\Models\Scopes;

use App\Models\ServerCommandExecution;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-require-extends ServerCommandExecution
 */
trait ServerCommandExecutionScopes
{
    /**
     * Limit the query to queued or running command executions.
     *
     * @param  Builder<ServerCommandExecution>  $query  The query to constrain.
     * @return Builder<ServerCommandExecution> The same query with the scope applied.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }
}
