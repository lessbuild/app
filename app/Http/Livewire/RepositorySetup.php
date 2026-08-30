<?php

namespace App\Http\Livewire;

use App\Models\Repository;
use App\Scripts\Repository\ActivateReleaseScript;
use App\Scripts\Repository\CheckoutRepositoryScript;
use App\Scripts\Repository\CloneRepositoryScript;
use App\Scripts\Repository\InstallDependenciesScript;
use App\Scripts\Repository\PurgeOldReleasesScript;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class RepositorySetup extends Component
{
    protected array $processes = [
        CloneRepositoryScript::class,
        CheckoutRepositoryScript::class,
        InstallDependenciesScript::class,
        ActivateReleaseScript::class,
        PurgeOldReleasesScript::class,
    ];

    /**
     * @var \App\Models\Repository
     */
    public Repository $model;

    /**
     * @return \Illuminate\Contracts\View\View
     *
     * @throws \Exception
     */
    public function render(): View
    {
        $this->model->refresh();
        abort_unless((int) auth()->id() === (int) $this->model->user_id, 403);

        return view('livewire.setup');
    }
}
