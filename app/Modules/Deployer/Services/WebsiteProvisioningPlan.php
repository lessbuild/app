<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Contracts\Scripts\WebsiteScript;
use App\Modules\Deployer\Scripts\Database\CreateMysqlDatabase;
use App\Modules\Deployer\Scripts\Server\UpdateEnviromentScript;
use App\Modules\Deployer\Scripts\Web\AddWebsiteToCaddyScript;

class WebsiteProvisioningPlan
{
    /**
     * @return list<class-string<WebsiteScript>>
     */
    public function scripts(): array
    {
        return [
            AddWebsiteToCaddyScript::class,
            CreateMysqlDatabase::class,
            UpdateEnviromentScript::class,
        ];
    }

    /**
     * Determine the website provisioning completion stage from its ordered scripts.
     *
     * @return int The number of website provisioning stages.
     */
    public function finalStage(): int
    {
        return count($this->scripts());
    }
}
