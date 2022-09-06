<?php

namespace App\Http\Livewire;

use App\Models\Server;
use App\Scripts\Cache\InstallMemcachedScript;
use App\Scripts\Cache\InstallRedisScript;
use App\Scripts\Database\InstallMysqlScript;
use App\Scripts\Languages\InstallNodeScript;
use App\Scripts\Languages\InstallPHPScript;
use App\Scripts\Server\BaseScript;
use App\Scripts\Server\ConfigureSwapScript;
use App\Scripts\Server\InstallComposerScript;
use App\Scripts\Server\RecipesScript;
use App\Scripts\Server\UpdateDependenciesScript;
use App\Scripts\Web\InstallCaddyScript;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ServerCommand extends Component
{
    /**
     * @var \App\Models\Server
     */
    public Server $model;

    /**
     * @return \Illuminate\Contracts\View\View
     *
     * @throws \Exception
     */
    public function render(): View
    {
        return view('livewire.scenes.servers.command');
    }
}
