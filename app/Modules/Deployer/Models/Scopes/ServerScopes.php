<?php

namespace App\Modules\Deployer\Models\Scopes;

use App\Modules\Deployer\Models\Enums\Server\ServerTypeEnum;
use App\Modules\Deployer\Models\Server;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-require-extends Server
 */
trait ServerScopes
{
    /**
     * Limit the query to active servers with a supported website runtime and database credentials.
     *
     * @param  Builder<Server>  $query  The query to constrain.
     * @return Builder<Server> The same query with the scope applied.
     */
    public function scopeReadyForWebsites(Builder $query): Builder
    {
        return $query
            ->where('provisioning_status', self::STATUS_ACTIVE)
            ->whereIn('type', ServerTypeEnum::websiteHostingValues())
            ->whereNotNull('mysql_root_password');
    }
}
