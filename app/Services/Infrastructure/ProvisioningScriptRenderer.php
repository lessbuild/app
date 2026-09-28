<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerScript;
use App\Models\Server;
use LogicException;

class ProvisioningScriptRenderer
{
    /**
     * The server's provisioning script: each stage's shell in order, numbered from 0 (the base script).
     *
     * @param  Server  $server
     * @param  list<class-string<ServerScript>>  $scripts
     * @return string
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
     *
     * @param  Server  $server
     * @param  ServerProvisioningPlan  $plan
     * @return string
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

    /**
     * Renders each script class through the container, refusing anything that isn't a ServerScript.
     *
     * @param  list<class-string<ServerScript>>  $scripts
     * @param  callable  $render
     * @param  int  $firstStep
     * @return string
     */
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
