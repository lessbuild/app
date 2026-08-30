<?php

namespace App\Services;

use App\Contracts\Scripts\BuildScript;
use App\Scripts\Repository\ActivateReleaseScript;
use App\Scripts\Repository\ArtisanCommandsScript;
use App\Scripts\Repository\CheckoutRepositoryScript;
use App\Scripts\Repository\CloneRepositoryScript;
use App\Scripts\Repository\InstallDependenciesScript;
use App\Scripts\Repository\PurgeOldReleasesScript;
use App\Scripts\Repository\SymlinkScript;
use App\Scripts\Repository\VerifyDeploymentHealthScript;

class RepositoryDeploymentPlan
{
    /**
     * @return list<class-string<BuildScript>>
     */
    public function scripts(): array
    {
        return [
            CloneRepositoryScript::class,
            CheckoutRepositoryScript::class,
            InstallDependenciesScript::class,
            ActivateReleaseScript::class,
            SymlinkScript::class,
            ArtisanCommandsScript::class,
            VerifyDeploymentHealthScript::class,
            PurgeOldReleasesScript::class,
        ];
    }

    public function finalStage(): int
    {
        return count($this->scripts());
    }
}
