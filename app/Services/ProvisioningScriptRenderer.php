<?php

namespace App\Services;

use App\Contracts\Scripts\BuildScript;
use App\Contracts\Scripts\ServerScript;
use App\Contracts\Scripts\WebsiteScript;
use App\Models\Build;
use App\Models\Server;
use App\Models\Website;
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
            ServerScript::class,
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
        $base = array_shift($scripts);
        $output = $this->server($server, [$base]);

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
     * @param  list<class-string<WebsiteScript>>  $scripts
     */
    public function website(Website $website, array $scripts): string
    {
        return $this->render(
            $scripts,
            WebsiteScript::class,
            fn (WebsiteScript $script, int $step): string => $script->script($step, $website),
        );
    }

    /**
     * @param  list<class-string<BuildScript>>  $scripts
     */
    public function build(Build $build, array $scripts): string
    {
        return $this->render(
            $scripts,
            BuildScript::class,
            fn (BuildScript $script, int $step): string => $script->script($step, $build),
        );
    }

    private function render(array $scripts, string $contract, callable $render, int $firstStep = 1): string
    {
        $output = '';

        foreach ($scripts as $index => $class) {
            $script = app($class);
            if (! $script instanceof $contract) {
                throw new LogicException("Provisioning script {$class} must implement {$contract}.");
            }

            $output .= $render($script, $index + $firstStep);
        }

        return $output;
    }
}
