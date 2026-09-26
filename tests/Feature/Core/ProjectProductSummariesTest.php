<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProjectEnvironmentAwareSummaryProvider;
use App\Core\Contracts\ProjectProductSummaryProvider;
use App\Core\Data\Projects\ProjectEnvironmentContext;
use App\Core\Data\Projects\ProjectEnvironmentContextState;
use App\Core\Data\Projects\ProjectProductSnapshot;
use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Services\ProjectProductSummaries;
use App\Core\Services\ProjectProductSummaryRegistry;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class ProjectProductSummariesTest extends TestCase
{
    public function test_a_product_database_failure_does_not_hide_other_project_summaries(): void
    {
        $registry = new ProjectProductSummaryRegistry;
        $registry->register('monitor', new class implements ProjectProductSummaryProvider
        {
            public function summarize(PlatformUser $user, Project $project): ?ProjectProductSnapshot
            {
                throw new \PDOException('Connection failed.');
            }
        });
        $registry->register('analytics', new class implements ProjectProductSummaryProvider
        {
            public function summarize(PlatformUser $user, Project $project): ?ProjectProductSnapshot
            {
                return new ProjectProductSnapshot(
                    title: 'Analytics traffic',
                    detail: '340 pageviews',
                    state: ProjectProductSnapshotState::Current,
                );
            }
        });

        $summaries = (new ProjectProductSummaries($registry))->forProject(
            user: new PlatformUser,
            project: new Project,
            products: ['monitor', 'analytics'],
        );

        $this->assertSame(ProjectProductSnapshotState::Unavailable, $summaries->get('monitor')->state);
        $this->assertSame('340 pageviews', $summaries->get('analytics')->detail);
        $this->assertSame(['monitor', 'analytics'], $summaries->keys()->all());
    }

    public function test_only_requested_product_providers_are_consulted(): void
    {
        $registry = new ProjectProductSummaryRegistry;
        $called = (object) ['value' => false];
        $registry->register('monitor', new class($called) implements ProjectProductSummaryProvider
        {
            public function __construct(private object $called) {}

            public function summarize(PlatformUser $user, Project $project): ?ProjectProductSnapshot
            {
                $this->called->value = true;

                return null;
            }
        });

        (new ProjectProductSummaries($registry))->forProject(
            user: new PlatformUser,
            project: new Project,
            products: ['analytics'],
        );

        $this->assertFalse($called->value);
    }

    public function test_unavailable_summary_does_not_claim_that_no_recent_activity_exists(): void
    {
        $summary = new ProjectProductSnapshot(
            title: 'Product data',
            detail: 'This application’s data is temporarily unavailable.',
            state: ProjectProductSnapshotState::Unavailable,
        );

        $html = Blade::render('<x-signal.ui.project-product-summary :summary="$summary" product-label="Monitor" compact />', [
            'summary' => $summary,
        ]);

        $this->assertStringContainsString('Freshness is unavailable while app data cannot be reached.', $html);
        $this->assertStringNotContainsString('No recent activity to report.', $html);
    }

    public function test_environment_context_uses_only_environment_aware_summaries(): void
    {
        $registry = new ProjectProductSummaryRegistry;
        $projectWideCalls = (object) ['count' => 0];
        $environment = (new ProjectEnvironment)->forceFill([
            'id' => '01J00000000000000000000001',
            'name' => 'Staging',
        ]);
        $registry->register('deployer', new class($projectWideCalls) implements ProjectEnvironmentAwareSummaryProvider
        {
            public function __construct(private object $calls) {}

            public function summarize(PlatformUser $user, Project $project): ?ProjectProductSnapshot
            {
                $this->calls->count++;

                return new ProjectProductSnapshot('Project activity', 'All environments', ProjectProductSnapshotState::Current);
            }

            public function summarizeForEnvironment(PlatformUser $user, Project $project, ProjectEnvironment $environment): ?ProjectProductSnapshot
            {
                return new ProjectProductSnapshot('Environment activity', $environment->name, ProjectProductSnapshotState::Current);
            }
        });
        $registry->register('monitor', new class($projectWideCalls) implements ProjectProductSummaryProvider
        {
            public function __construct(private object $calls) {}

            public function summarize(PlatformUser $user, Project $project): ?ProjectProductSnapshot
            {
                $this->calls->count++;

                return new ProjectProductSnapshot('Project activity', 'All environments', ProjectProductSnapshotState::Current);
            }
        });

        $summaries = (new ProjectProductSummaries($registry))->forProject(
            user: new PlatformUser,
            project: new Project,
            products: ['deployer', 'monitor'],
            environmentContext: new ProjectEnvironmentContext(ProjectEnvironmentContextState::Selected, $environment),
        );

        $this->assertSame('Staging', $summaries->get('deployer')->detail);
        $this->assertSame(ProjectProductSnapshotState::Unavailable, $summaries->get('monitor')->state);
        $this->assertStringContainsString('Staging', $summaries->get('monitor')->detail);
        $this->assertSame(0, $projectWideCalls->count);
    }

    public function test_unavailable_environment_never_calls_a_product_summary_provider(): void
    {
        $registry = new ProjectProductSummaryRegistry;
        $called = (object) ['value' => false];
        $registry->register('deployer', new class($called) implements ProjectProductSummaryProvider
        {
            public function __construct(private object $called) {}

            public function summarize(PlatformUser $user, Project $project): ?ProjectProductSnapshot
            {
                $this->called->value = true;

                return null;
            }
        });

        $summaries = (new ProjectProductSummaries($registry))->forProject(
            user: new PlatformUser,
            project: new Project,
            products: ['deployer'],
            environmentContext: new ProjectEnvironmentContext(ProjectEnvironmentContextState::Unavailable),
        );

        $this->assertFalse($called->value);
        $this->assertSame(ProjectProductSnapshotState::Unavailable, $summaries->get('deployer')->state);
        $this->assertStringContainsString('selected environment is unavailable', $summaries->get('deployer')->detail);
    }
}
