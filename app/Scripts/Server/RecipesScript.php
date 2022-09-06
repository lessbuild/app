<?php

namespace App\Scripts\Server;

use App\Models\Server;

class RecipesScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Run Recipes';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Run user defined recipes';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'ran-recipes';

    /**
     * Script to run
     *
     * @param int $step
     * @param \App\Models\Server $server
     * @return string
     */
    public function script(int $step, Server $server): string
    {
        return <<<SCRIPT
        provisionPing {$server->id} {$step}
        SCRIPT;
    }
}
