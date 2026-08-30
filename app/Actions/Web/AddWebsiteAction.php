<?php

namespace App\Actions\Web;

use App\Abstracts\Publishable;
use App\Models\Website;
use App\Scripts\Database\CreateMysqlDatabase;
use App\Scripts\Server\UpdateEnviromentScript;
use App\Scripts\Web\AddWebsiteToCaddyScript;

class AddWebsiteAction extends Publishable
{
    // Scripts to run
    public array $commands = [
        AddWebsiteToCaddyScript::class,
        CreateMysqlDatabase::class,
        UpdateEnviromentScript::class,
    ];

    /**
     * @var \App\Models\Website
     */
    private Website $website;

    /**
     * @param  \App\Models\Website  $website
     *
     * @throws \Exception
     */
    public function __construct(Website $website)
    {
        parent::__construct($website->server);

        $this->website = $website;
    }

    /**
     * @return void
     *
     * @throws \Exception
     */
    public function handle(): void
    {
        $failureCallback = \Illuminate\Support\Facades\URL::signedRoute('callbacks.website.failed', $this->website);
        $this->script = <<<SCRIPT
        #!/bin/bash
        set -Eeuo pipefail
        trap 'exit_code=$?; curl --silent --show-error --data "exit_code=\$exit_code&message=Remote website provisioning failed" "{$failureCallback}"; exit \$exit_code' ERR

        SCRIPT;

        foreach ($this->commands as $key => $command) {
            $this->script .= app($command)->script(($key + 1), $this->website);
        }

        $this->makeScriptFile($this->website->name);

        $this->upload();

        $this->run();
    }
}
