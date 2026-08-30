<?php

namespace App\Actions\Web;

use App\Models\Website;
use App\Scripts\Web\DeleteWebsiteCaddy;
use App\Services\Runner;

class DeleteWebsiteFromCaddyAction
{
    // Scripts to run
    public array $commands = [
        DeleteWebsiteCaddy::class,
    ];

    private Website $website;

    public function __construct(Website $website)
    {
        $this->website = $website;
    }

    /**
     * @return void
     *
     * @throws \Exception
     */
    public function handle()
    {
        // Scripts to run
        $script = null;
        foreach ($this->commands as $command) {
            $script .= (new $command)->script($this->website)."\n";
        }

        $run = (new Runner)->server($this->website->server)->create();

        // Run the script
        $run->execute($script);
    }
}
