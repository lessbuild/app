<?php

namespace App\Actions\Repository;

use App\Abstracts\Publishable;
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

    /**
     * Publish Repository Action constructor
     *
     * @param \App\Models\Repository $repository
     * @throws \Exception
     */
    public function __construct(Repository $repository)
    {
        parent::__construct($repository->website->server);

        $this->repository = $repository;
    }

    /**
     * @return void
     *
     * @throws \Exception
     */
    public function handle(): void
    {
        $build = $this->repository->builds()->create([
            'built_at' => now(),
        ]);

        foreach ($this->commands as $key => $command) {
            $this->script .= app($command)->script(($key + 1), $this->repository);
        }

        $this->makeScriptFile($this->repository->name);

        $this->upload();

        $this->run();
    }
}
