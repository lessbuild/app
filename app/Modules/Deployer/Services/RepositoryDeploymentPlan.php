<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Contracts\Scripts\BuildScript;
use App\Modules\Deployer\Scripts\Repository\ActivateReleaseScript;
use App\Modules\Deployer\Scripts\Repository\ArtisanCommandsScript;
use App\Modules\Deployer\Scripts\Repository\CheckoutRepositoryScript;
use App\Modules\Deployer\Scripts\Repository\CloneRepositoryScript;
use App\Modules\Deployer\Scripts\Repository\ConfigureProcessesScript;
use App\Modules\Deployer\Scripts\Repository\ConfigureResourcesScript;
use App\Modules\Deployer\Scripts\Repository\ConfigureWebRuntimeScript;
use App\Modules\Deployer\Scripts\Repository\InstallDependenciesScript;
use App\Modules\Deployer\Scripts\Repository\PurgeOldReleasesScript;
use App\Modules\Deployer\Scripts\Repository\RunBuildCommandsScript;
use App\Modules\Deployer\Scripts\Repository\RunPostDeploymentCommandsScript;
use App\Modules\Deployer\Scripts\Repository\SymlinkScript;
use App\Modules\Deployer\Scripts\Repository\SyncEnvironmentScript;
use App\Modules\Deployer\Scripts\Repository\ValidateCandidateScript;
use App\Modules\Deployer\Scripts\Repository\VerifyDeploymentHealthScript;

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

    /**
     * Determine the completion stage from the ordered repository deployment scripts.
     *
     * @return int The number of deployment stages.
     */
    public function finalStage(): int
    {
        return count($this->scripts());
    }

    /**
     * Find the one-based stage for a deployment script without duplicating the plan order in a reader.
     *
     * @param  class-string<BuildScript>  $script  Script whose callback stage should be located.
     * @return int|null The one-based stage, or null when this plan does not contain the script.
     */
    public function stageFor(string $script): ?int
    {
        $index = array_search($script, $this->scripts(), true);

        return $index === false ? null : $index + 1;
    }

    /**
     * Locate the one-based stage that activates the candidate release.
     *
     * @return int The activation stage, or the final stage when no activation script is present.
     */
    public function activationStage(): int
    {
        $index = array_search(ActivateReleaseScript::class, $this->scripts(), true);

        return $index === false ? $this->finalStage() : $index + 1;
    }

    /**
     * Locate the stage that finishes managed-resource initialization.
     *
     * @return int The one-based resource configuration stage, or the final stage when the plan has no resource step.
     */
    public function resourceStage(): int
    {
        $index = array_search(ConfigureResourcesScript::class, $this->scripts(), true);

        return $index === false ? $this->finalStage() : $index + 1;
    }

    /**
     * Locate the existing post-deployment stage used by preview initialization.
     *
     * @return int The one-based post-deployment stage, or the final stage when absent.
     */
    public function postDeploymentStage(): int
    {
        $index = array_search(RunPostDeploymentCommandsScript::class, $this->scripts(), true);

        return $index === false ? $this->finalStage() : $index + 1;
    }
}
