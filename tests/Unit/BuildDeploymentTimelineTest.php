<?php

namespace Tests\Unit;

use App\Models\Build;
use App\Models\Environment;
use App\Services\BuildDeploymentTimeline;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class BuildDeploymentTimelineTest extends TestCase
{
    public function test_successful_builds_show_all_milestones_as_complete_even_when_legacy_progress_is_missing(): void
    {
        $createdAt = CarbonImmutable::parse('2026-09-13 08:00:00 UTC');
        $activatedAt = CarbonImmutable::parse('2026-09-13 08:04:00 UTC');
        $finishedAt = CarbonImmutable::parse('2026-09-13 08:06:00 UTC');
        $build = new Build([
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat('a', 40),
            'setup_stage' => 0,
        ]);
        $build->created_at = $createdAt;
        $build->activated_at = $activatedAt;
        $build->finished_at = $finishedAt;

        $entries = app(BuildDeploymentTimeline::class)->for($build);

        $this->assertSame([
            'requested', 'provisioning', 'build', 'migration', 'release', 'traffic',
            'resources', 'health', 'finalization',
        ], array_map(fn ($entry): string => $entry->key, $entries));
        $this->assertNotEmpty($entries);
        $this->assertCount(9, $entries);
        $this->assertCount(9, array_filter($entries, fn ($entry): bool => $entry->status === BuildDeploymentTimeline::STATUS_COMPLETED));
        $this->assertTrue($entries[0]->occurredAt->equalTo($createdAt));
        $this->assertTrue($entries[4]->occurredAt->equalTo($activatedAt));
        $this->assertTrue($entries[8]->occurredAt->equalTo($finishedAt));
    }

    public function test_active_build_marks_the_current_stage_range_active_and_later_milestones_pending(): void
    {
        $build = new Build([
            'status' => Build::STATUS_RUNNING,
            'setup_stage' => 4,
        ]);

        $entries = app(BuildDeploymentTimeline::class)->for($build);
        $byKey = collect($entries)->keyBy('key');

        $this->assertSame(BuildDeploymentTimeline::STATUS_COMPLETED, $byKey['provisioning']->status);
        $this->assertSame(BuildDeploymentTimeline::STATUS_ACTIVE, $byKey['build']->status);
        $this->assertSame(BuildDeploymentTimeline::STATUS_PENDING, $byKey['migration']->status);
        $this->assertSame(BuildDeploymentTimeline::STATUS_PENDING, $byKey['finalization']->status);
    }

    public function test_failed_build_marks_the_first_unrecorded_milestone_failed_without_claiming_later_work_ran(): void
    {
        $build = new Build([
            'status' => Build::STATUS_FAILED,
            'setup_stage' => 6,
            'finished_at' => CarbonImmutable::parse('2026-09-13 08:06:00 UTC'),
        ]);

        $entries = app(BuildDeploymentTimeline::class)->for($build);
        $byKey = collect($entries)->keyBy('key');

        $this->assertSame(BuildDeploymentTimeline::STATUS_COMPLETED, $byKey['build']->status);
        $this->assertSame(BuildDeploymentTimeline::STATUS_FAILED, $byKey['migration']->status);
        $this->assertSame(BuildDeploymentTimeline::STATUS_PENDING, $byKey['release']->status);
        $this->assertSame(BuildDeploymentTimeline::STATUS_PENDING, $byKey['finalization']->status);
        $this->assertNull($byKey['migration']->occurredAt);
        $this->assertNull($byKey['finalization']->occurredAt);
    }

    public function test_canceled_build_preserves_completed_progress_and_marks_unstarted_milestones_canceled(): void
    {
        $build = new Build([
            'status' => Build::STATUS_CANCELED,
            'setup_stage' => 5,
        ]);

        $entries = app(BuildDeploymentTimeline::class)->for($build);
        $byKey = collect($entries)->keyBy('key');

        $this->assertSame(BuildDeploymentTimeline::STATUS_COMPLETED, $byKey['provisioning']->status);
        $this->assertSame(BuildDeploymentTimeline::STATUS_COMPLETED, $byKey['build']->status);
        $this->assertSame(BuildDeploymentTimeline::STATUS_CANCELED, $byKey['migration']->status);
        $this->assertSame(BuildDeploymentTimeline::STATUS_CANCELED, $byKey['health']->status);
    }

    public function test_required_approval_is_inserted_when_the_environment_requires_it(): void
    {
        $build = new Build([
            'status' => Build::STATUS_AWAITING_APPROVAL,
            'setup_stage' => 0,
        ]);
        $build->setRelation('environment', new Environment(['requires_deployment_approval' => true]));

        $entries = app(BuildDeploymentTimeline::class)->for($build);

        $this->assertSame('approval', $entries[1]->key);
        $this->assertSame(BuildDeploymentTimeline::STATUS_ACTIVE, $entries[1]->status);
        $this->assertSame(BuildDeploymentTimeline::STATUS_PENDING, $entries[2]->status);
    }
}
