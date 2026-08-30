<?php

namespace App\Actions\Web;

use App\Models\Website;
use App\Scripts\Web\DeleteWebsiteCaddy;
use App\Services\Runner;
use RuntimeException;

class DeleteWebsiteFromCaddyAction
{
    // Scripts to run
    public array $commands = [
        DeleteWebsiteCaddy::class,
    ];

    private Website $website;

    private Runner $runner;

    public function __construct(Website $website, ?Runner $runner = null)
    {
        $this->website = $website;
        $this->runner = $runner ?? new Runner;
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

        $run = $this->runner->server($this->website->server)->create();

        $result = $run->execute($script);
        if (! $result->isSuccessful()) {
            throw new RuntimeException(
                'Unable to remove the website from its server: '
                .trim($result->getErrorOutput() ?: $result->getOutput()),
            );
        }
    }
}
