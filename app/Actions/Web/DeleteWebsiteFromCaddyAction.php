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

    /**
     * @var \App\Models\Website
     */
    private Website $website;

    /**
     * @param  \App\Models\Website  $website
     */
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

        $command = $this->sanitizeScript($this->website, $script);

        $run = (new Runner())->server($this->website->server)->create();

        // Run the script
        $run->execute($command);
    }

    /**
     * @param  \App\Models\Website  $website
     * @param  string  $script
     * @return string
     */
    private function sanitizeScript(Website $website, string $script)
    {
        return str_replace([
            'SERVER_ID$',
            'SITEURL$',
            'SITENAME$',
        ], [
            $website->server->id,
            $website->url,
            $website->name,
        ], $script);
    }
}
