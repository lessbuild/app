<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProjectProductSummaryProvider;
use App\Core\Data\Projects\ProjectProductSnapshot;
use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Services\ProjectProductSummaries;
use App\Core\Services\ProjectProductSummaryRegistry;
use Illuminate\Database\QueryException;
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
                throw new QueryException('monitor', 'select * from incidents', [], new \RuntimeException('Connection failed.'));
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
}
