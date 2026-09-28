<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Contracts\Deploy\BuildScript;
use App\Services\Deploy\Scripts\ActivateReleaseScript;
use App\Services\Deploy\Scripts\ArtisanCommandsScript;
use App\Services\Deploy\Scripts\CheckoutRepositoryScript;
use App\Services\Deploy\Scripts\CloneRepositoryScript;
use App\Services\Deploy\Scripts\ConfigureProcessesScript;
use App\Services\Deploy\Scripts\ConfigureResourcesScript;
use App\Services\Deploy\Scripts\ConfigureWebRuntimeScript;
use App\Services\Deploy\Scripts\InstallDependenciesScript;
use App\Services\Deploy\Scripts\PurgeOldReleasesScript;
use App\Services\Deploy\Scripts\RunBuildCommandsScript;
use App\Services\Deploy\Scripts\RunPostDeploymentCommandsScript;
use App\Services\Deploy\Scripts\SymlinkScript;
use App\Services\Deploy\Scripts\SyncEnvironmentScript;
use App\Services\Deploy\Scripts\ValidateCandidateScript;
use App\Services\Deploy\Scripts\VerifyDeploymentHealthScript;

class RepositoryDeploymentPlan
{
    /**
     * List the deploy's stages in order, from cloning to purging old releases. Each stage's position is the progress
     * number it reports.
     *
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
