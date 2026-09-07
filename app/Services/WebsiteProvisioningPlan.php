<?php

namespace App\Services;

use App\Contracts\Scripts\WebsiteScript;
use App\Scripts\Database\CreateMysqlDatabase;
use App\Scripts\Server\UpdateEnviromentScript;
use App\Scripts\Web\AddWebsiteToCaddyScript;

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
