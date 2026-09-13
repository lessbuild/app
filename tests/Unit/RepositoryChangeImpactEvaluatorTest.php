<?php

namespace Tests\Unit;

use App\Data\RepositoryChangeImpact;
use App\Models\Repository;
use App\Services\RepositoryChangeImpactEvaluator;
use Tests\TestCase;

class RepositoryChangeImpactEvaluatorTest extends TestCase
{
    public function test_include_and_exclude_patterns_define_the_automatic_deployment_scope(): void
    {
        $repository = new Repository([
            'auto_deploy_include_paths' => ['apps/storefront/**', 'packages/shared/**'],
            'auto_deploy_exclude_paths' => ['apps/storefront/docs/**'],
        ]);

        $impact = app(RepositoryChangeImpactEvaluator::class)->evaluate($repository, [
            'apps/storefront/resources/views/home.blade.php',
            'apps/storefront/docs/README.md',
            'docs/architecture.md',
        ]);

        $this->assertSame(RepositoryChangeImpact::AFFECTED, $impact->status);
        $this->assertSame(['apps/storefront/resources/views/home.blade.php'], $impact->matchedPaths);
        $this->assertSame('configured_path_changed', $impact->reason);
    }

    public function test_empty_filter_configuration_preserves_deploy_every_matching_push_behavior(): void
    {
        $repository = new Repository;

        $impact = app(RepositoryChangeImpactEvaluator::class)->evaluate($repository, null);

        $this->assertSame(RepositoryChangeImpact::AFFECTED, $impact->status);
        $this->assertSame('no_path_filters', $impact->reason);
    }

    public function test_unavailable_or_unsafe_changed_paths_are_never_treated_as_unaffected(): void
    {
        $repository = new Repository([
            'auto_deploy_include_paths' => ['apps/**'],
        ]);
        $evaluator = app(RepositoryChangeImpactEvaluator::class);

        $this->assertSame(
            RepositoryChangeImpact::UNKNOWN,
            $evaluator->evaluate($repository, null)->status,
        );
        $this->assertSame(
            RepositoryChangeImpact::UNKNOWN,
            $evaluator->evaluate($repository, ['../secrets.env'])->status,
        );
    }

    public function test_no_matching_changed_path_is_unaffected(): void
    {
        $repository = new Repository([
            'auto_deploy_include_paths' => ['apps/**'],
        ]);

        $impact = app(RepositoryChangeImpactEvaluator::class)->evaluate($repository, ['docs/readme.md']);

        $this->assertTrue($impact->isUnaffected());
        $this->assertSame(['docs/readme.md'], $impact->changedPaths);
        $this->assertSame('no_configured_path_changed', $impact->reason);
    }

    public function test_pending_delivery_path_sets_are_merged_conservatively(): void
    {
        $evaluator = app(RepositoryChangeImpactEvaluator::class);

        $this->assertSame(
            ['apps/api/app.php', 'packages/shared/composer.json'],
            $evaluator->mergeChangedPaths([
                ['apps/api/app.php'],
                ['apps/api/app.php', 'packages/shared/composer.json'],
            ]),
        );
        $this->assertNull($evaluator->mergeChangedPaths([
            ['apps/api/app.php'],
            null,
        ]));
    }
}
