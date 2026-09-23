<?php

namespace Tests\Unit;

use App\Modules\Deployer\Contracts\Scripts\BuildScript;
use App\Modules\Deployer\Contracts\Scripts\ServerScript;
use App\Modules\Deployer\Contracts\Scripts\WebsiteScript;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Services\ProvisioningScriptRenderer;
use App\Modules\Deployer\Services\RepositoryDeploymentPlan;
use App\Modules\Deployer\Services\ServerProvisioningPlan;
use App\Modules\Deployer\Services\WebsiteProvisioningPlan;
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

    public function test_renderer_separates_website_script_fragments(): void
    {
        $rendered = app(ProvisioningScriptRenderer::class)->website(new Website, [
            FirstWebsiteScript::class,
            SecondWebsiteScript::class,
        ]);

        $this->assertSame("first\nsecond\n", $rendered);
    }

    public function test_renderer_separates_build_script_fragments(): void
    {
        $rendered = app(ProvisioningScriptRenderer::class)->build(new Build, [
            FirstBuildScript::class,
            SecondBuildScript::class,
        ]);

        $this->assertSame("first\nsecond\n", $rendered);
    }
}

class InvalidProvisioningScript
{
    public function script(): string
    {
        return 'unsafe';
    }
}

class FirstWebsiteScript implements WebsiteScript
{
    public function script(int $step, Website $website): string
    {
        return 'first';
    }
}

class SecondWebsiteScript implements WebsiteScript
{
    public function script(int $step, Website $website): string
    {
        return 'second';
    }
}

class FirstBuildScript implements BuildScript
{
    public function script(int $step, Build $build): string
    {
        return 'first';
    }
}

class SecondBuildScript implements BuildScript
{
    public function script(int $step, Build $build): string
    {
        return 'second';
    }
}
