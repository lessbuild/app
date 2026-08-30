<?php

namespace App\Scripts\Server;

use App\Models\Server;

class RecipesScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Run Recipes';

    /**
     * Description of the script
     */
    public static string $description = 'Run user defined recipes';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'ran-recipes';

    /**
     * Script to run
     */
    public function script(int $step, Server $server): string
    {
        $recipes = $server->recipes()->get()->map(function ($recipe): string {
            $name = escapeshellarg("Running recipe: {$recipe->name}");

            return <<<SCRIPT
            printf '%s\\n' {$name}
            (
              set -Eeuo pipefail
            {$recipe->script}
            )
            SCRIPT;
        })->implode("\n\n");

        return trim($recipes).($recipes ? "\n\n" : '')."provisionPing {$server->id} {$step}";
    }
}
