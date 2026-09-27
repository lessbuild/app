<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerScript;
use App\Models\Server;
use LogicException;

class ProvisioningScriptRenderer
{
    /**
     * @param  list<class-string<ServerScript>>  $scripts
     */
    public function server(Server $server, array $scripts): string
    {
        return $this->render(
            $scripts,
            fn (ServerScript $script, int $step): string => $script->script($step, $server)."\n",
            0,
        );
    }

    /**
     * Render the base script and every step after the last confirmed stage.
     */
    public function remainingServer(Server $server, ServerProvisioningPlan $plan): string
    {
        $scripts = $plan->scripts($server);
        $output = $this->server($server, array_slice($scripts, 0, 1));
        $scripts = array_slice($scripts, 1);

        foreach ($scripts as $index => $class) {
            $step = $index + 1;
            if ($step <= $server->setup_stage) {
                continue;
            }

            $script = app($class);
            if (! $script instanceof ServerScript) {
                throw new LogicException("Provisioning script {$class} must implement ".ServerScript::class.'.');
            }

            $output .= $script->script($step, $server)."\n";
        }

        return $output;
    }

    /** @param list<class-string<ServerScript>> $scripts */
    private function render(array $scripts, callable $render, int $firstStep): string
    {
        $output = '';
        foreach ($scripts as $index => $class) {
            $script = app($class);
            if (! $script instanceof ServerScript) {
                throw new LogicException("Provisioning script {$class} must implement ".ServerScript::class.'.');
            }
            $output .= $render($script, $index + $firstStep);
        }

        return $output;
    }
}
