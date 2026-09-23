<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProjectSetupProvider;
use App\Core\Data\Projects\ProjectSetupStep;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Services\ProjectSetup;
use App\Core\Services\ProjectSetupRegistry;
use Illuminate\Database\LostConnectionException;
use Tests\TestCase;

final class ProjectSetupTest extends TestCase
{
    public function test_enabled_products_get_resumable_steps_and_database_failures_are_isolated(): void
    {
        $registry = new ProjectSetupRegistry;
        $called = (object) ['value' => false];
        $registry->register('deployer', new class($called) implements ProjectSetupProvider
        {
            public function __construct(private object $called) {}

            public function steps(PlatformUser $user, Project $project): array
            {
                $this->called->value = true;

                return [new ProjectSetupStep(
                    id: 'deployer.repository',
                    product: 'deployer',
                    title: 'Connect a repository',
                    detail: 'Resume by returning to the existing deployment project.',
                    state: ProjectSetupStepState::NeedsAction,
                    url: '/projects/4',
                    actionLabel: 'Open project',
                )];
            }
        });
        $registry->register('monitor', new class implements ProjectSetupProvider
        {
            public function steps(PlatformUser $user, Project $project): array
            {
                throw new LostConnectionException('Monitor is temporarily unavailable.');
            }
        });

        $steps = (new ProjectSetup($registry))->forProject(
            user: new PlatformUser,
            project: new Project,
            products: ['deployer', 'monitor'],
        );

        $this->assertTrue($called->value);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $steps->get('deployer.repository')->state);
        $this->assertSame('/projects/4', $steps->get('deployer.repository')->url);
        $this->assertSame(ProjectSetupStepState::Unavailable, $steps->get('monitor.unavailable')->state);
        $this->assertSame(['deployer.repository', 'monitor.unavailable'], $steps->keys()->all());
    }

    public function test_unenabled_product_setup_provider_is_not_consulted(): void
    {
        $registry = new ProjectSetupRegistry;
        $called = (object) ['value' => false];
        $registry->register('analytics', new class($called) implements ProjectSetupProvider
        {
            public function __construct(private object $called) {}

            public function steps(PlatformUser $user, Project $project): array
            {
                $this->called->value = true;

                return [];
            }
        });

        $steps = (new ProjectSetup($registry))->forProject(
            user: new PlatformUser,
            project: new Project,
            products: ['deployer'],
        );

        $this->assertFalse($called->value);
        $this->assertTrue($steps->isEmpty());
    }
}
