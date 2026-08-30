<?php

namespace App\Http\Livewire;

use App\Models\Build;
use App\Models\Repository;
use App\Scripts\Repository\ActivateReleaseScript;
use App\Scripts\Repository\ArtisanCommandsScript;
use App\Scripts\Repository\CheckoutRepositoryScript;
use App\Scripts\Repository\CloneRepositoryScript;
use App\Scripts\Repository\InstallDependenciesScript;
use App\Scripts\Repository\PurgeOldReleasesScript;
use App\Scripts\Repository\SymlinkScript;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class RepositorySetup extends Component
{
    protected array $processes = [
        CloneRepositoryScript::class,
        CheckoutRepositoryScript::class,
        InstallDependenciesScript::class,
        ActivateReleaseScript::class,
        SymlinkScript::class,
        ArtisanCommandsScript::class,
        PurgeOldReleasesScript::class,
    ];

    public Repository $model;

    /**
     * @throws \Exception
     */
    public function render(): View
    {
        $this->model->refresh();
        abort_unless((int) auth()->id() === (int) $this->model->user_id, 403);
        $latestBuild = $this->model->builds()->latest()->first();
        $this->model->setAttribute('provisioning_status', match ($latestBuild?->status) {
            Build::STATUS_SUCCEEDED => 'active',
            Build::STATUS_FAILED => 'failed',
            Build::STATUS_CANCELED => 'canceled',
            default => $latestBuild?->status,
        });
        $this->model->setAttribute('provisioning_error', $latestBuild?->failure_message);

        return view('livewire.setup', [
            'processes' => $this->processes,
        ]);
    }
}
