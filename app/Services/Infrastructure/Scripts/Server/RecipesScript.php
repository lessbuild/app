<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Scripts\Server;

use App\Contracts\Infrastructure\ServerScript;
use App\Models\Server;

class RecipesScript implements ServerScript
{
    public const TITLE = 'Run Recipes';

    public const DESCRIPTION = 'Run user defined recipes';

    public const IDENTIFIER = 'ran-recipes';

    /**
     * Script to run
     */
    public function script(int $step, Server $server): string
    {
        $recipes = collect($server->provisioningRecipes())->map(function (array $recipe): string {
            $name = escapeshellarg("Running recipe: {$recipe['name']}");

            return <<<SCRIPT
            printf '%s\\n' {$name}
            (
              set -Eeuo pipefail
            {$recipe['script']}
            )
            SCRIPT;
        })->implode("\n\n");

        return trim($recipes).($recipes ? "\n\n" : '')."provisionPing {$server->id} {$step}";
    }
}
