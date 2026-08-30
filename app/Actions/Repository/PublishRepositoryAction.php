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

    /**
     * @var \App\Models\Repository
     */
    private Repository $repository;

    private Build $build;

    /**
     * Publish Repository Action constructor
     *
     * @param  \App\Models\Build  $build
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
     * @return void
     *
     * @throws \Exception
     */
    public function handle(): void
    {
        $failureCallback = \Illuminate\Support\Facades\URL::signedRoute('callbacks.build.failed', $this->build);
        $this->script = <<<SCRIPT
        #!/bin/bash
        set -Eeuo pipefail
        trap 'exit_code=$?; curl --silent --show-error --data "exit_code=\$exit_code&message=Remote deployment script failed" "{$failureCallback}"; exit \$exit_code' ERR

        SCRIPT;

        foreach ($this->commands as $key => $command) {
            $this->script .= app($command)->script(($key + 1), $this->repository);
        }

        $this->makeScriptFile($this->repository->name);

        $this->upload();

        $this->run();
    }
}
