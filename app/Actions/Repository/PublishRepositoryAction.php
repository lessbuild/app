<?php

namespace App\Actions\Repository;

use App\Abstracts\Publishable;
use App\Models\Build;
use App\Models\Repository;
use App\Scripts\Repository\ActivateReleaseScript;
use App\Scripts\Repository\ArtisanCommandsScript;
use App\Scripts\Repository\CheckoutRepositoryScript;
use App\Scripts\Repository\CloneRepositoryScript;
use App\Scripts\Repository\InstallDependenciesScript;
use App\Scripts\Repository\PurgeOldReleasesScript;
use App\Scripts\Repository\SymlinkScript;
use App\Services\ProvisioningCallbackUrl;

class PublishRepositoryAction extends Publishable
{
    // Scripts to run
    public array $commands = [
        CloneRepositoryScript::class,
        CheckoutRepositoryScript::class,
        InstallDependenciesScript::class,
        ActivateReleaseScript::class,
        SymlinkScript::class,
        ArtisanCommandsScript::class,
        PurgeOldReleasesScript::class,
    ];

    private Repository $repository;

    private Build $build;

    /**
     * Publish Repository Action constructor
     *
     *
     * @throws \Exception
     */
    public function __construct(Build $build)
    {
        $repository = $build->repository;
        parent::__construct($repository->website->server);

        $this->repository = $repository;
        $this->build = $build;
    }

    /**
     * @throws \Exception
     */
    public function handle(): void
    {
        $failureCallback = ProvisioningCallbackUrl::buildFailure($this->build);
        $this->script = <<<SCRIPT
        #!/bin/bash
        set -Eeuo pipefail
        trap 'exit_code=$?; curl --silent --show-error --data "exit_code=\$exit_code&message=Remote deployment script failed" "{$failureCallback}"; exit \$exit_code' ERR

        SCRIPT;

        foreach ($this->commands as $key => $command) {
            $this->script .= app($command)->script(($key + 1), $this->build);
        }

        $this->makeScriptFile($this->repository->name);

        $this->upload();

        $this->run();
    }
}
