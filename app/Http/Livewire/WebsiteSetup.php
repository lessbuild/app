<?php

namespace App\Http\Livewire;

use App\Models\Website;
use App\Scripts\Database\CreateMysqlDatabase;
use App\Scripts\Server\UpdateEnviromentScript;
use App\Scripts\Web\AddWebsiteToCaddyScript;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class WebsiteSetup extends Component
{
    protected array $processes = [
        AddWebsiteToCaddyScript::class,
        CreateMysqlDatabase::class,
        UpdateEnviromentScript::class
    ];

    /**
     * @var \App\Models\Website
     */
    public Website $model;

    /**
     * @return \Illuminate\Contracts\View\View
     *
     * @throws \Exception
     */
    public function render(): View
    {
        return view('livewire.setup');
    }
}
