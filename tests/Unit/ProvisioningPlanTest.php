<?php

namespace Tests\Unit;

use App\Contracts\Scripts\BuildScript;
use App\Contracts\Scripts\ServerScript;
use App\Contracts\Scripts\WebsiteScript;
use App\Models\Server;
use App\Services\ProvisioningScriptRenderer;
use App\Services\RepositoryDeploymentPlan;
use App\Services\ServerProvisioningPlan;
use App\Services\WebsiteProvisioningPlan;
use LogicException;
use Tests\TestCase;

class ProvisioningPlanTest extends TestCase
{
    public function test_every_planned_step_has_the_correct_contract_and_ui_metadata(): void
    {
        $plans = [
            [app(ServerProvisioningPlan::class)->steps('app'), ServerScript::class],
            [app(WebsiteProvisioningPlan::class)->scripts(), WebsiteScript::class],
            [app(RepositoryDeploymentPlan::class)->scripts(), BuildScript::class],
        ];

        foreach ($plans as [$scripts, $contract]) {
            $identifiers = [];
            foreach ($scripts as $script) {
                $this->assertInstanceOf($contract, app($script));
                $this->assertTrue(property_exists($script, 'title'), "{$script} must define a title.");
                $this->assertTrue(property_exists($script, 'description'), "{$script} must define a description.");
                $this->assertTrue(property_exists($script, 'identifier'), "{$script} must define an identifier.");
                $identifiers[] = $script::$identifier;
            }

            $this->assertSame($identifiers, array_values(array_unique($identifiers)));
        }
    }

    public function test_final_stages_are_derived_from_each_plan(): void
    {
        $server = app(ServerProvisioningPlan::class);
        $website = app(WebsiteProvisioningPlan::class);
        $repository = app(RepositoryDeploymentPlan::class);

        $this->assertSame(count($server->steps('database')), $server->finalStage('database'));
        $this->assertSame(count($website->scripts()), $website->finalStage());
        $this->assertSame(count($repository->scripts()), $repository->finalStage());
    }

    public function test_renderer_rejects_a_script_from_the_wrong_context(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement '.ServerScript::class);

        app(ProvisioningScriptRenderer::class)->server(new Server, [InvalidProvisioningScript::class]);
    }
}

class InvalidProvisioningScript
{
    public function script(): string
    {
        return 'unsafe';
    }
}
