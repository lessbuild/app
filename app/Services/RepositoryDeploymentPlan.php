<?php

namespace App\Services;

use App\Contracts\Scripts\BuildScript;
use App\Scripts\Repository\ActivateReleaseScript;
use App\Scripts\Repository\ArtisanCommandsScript;
use App\Scripts\Repository\CheckoutRepositoryScript;
use App\Scripts\Repository\CloneRepositoryScript;
use App\Scripts\Repository\ConfigureProcessesScript;
use App\Scripts\Repository\ConfigureResourcesScript;
use App\Scripts\Repository\ConfigureWebRuntimeScript;
use App\Scripts\Repository\InstallDependenciesScript;
use App\Scripts\Repository\PurgeOldReleasesScript;
use App\Scripts\Repository\RunBuildCommandsScript;
use App\Scripts\Repository\RunPostDeploymentCommandsScript;
use App\Scripts\Repository\SymlinkScript;
use App\Scripts\Repository\SyncEnvironmentScript;
use App\Scripts\Repository\ValidateCandidateScript;
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
            SyncEnvironmentScript::class,
            InstallDependenciesScript::class,
            RunBuildCommandsScript::class,
            SymlinkScript::class,
            ArtisanCommandsScript::class,
            ValidateCandidateScript::class,
            ActivateReleaseScript::class,
            ConfigureWebRuntimeScript::class,
            ConfigureResourcesScript::class,
            ConfigureProcessesScript::class,
            RunPostDeploymentCommandsScript::class,
            VerifyDeploymentHealthScript::class,
            PurgeOldReleasesScript::class,
        ];
    }

    public function finalStage(): int
    {
        return count($this->scripts());
    }

    public function activationStage(): int
    {
        $index = array_search(ActivateReleaseScript::class, $this->scripts(), true);

        return $index === false ? $this->finalStage() : $index + 1;
    }
}
