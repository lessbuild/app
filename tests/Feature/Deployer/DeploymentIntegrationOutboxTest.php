<?php

namespace Tests\Feature\Deployer;

use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\DeploymentSucceededOutboxEvent;
use App\Modules\Deployer\Services\Integration\RecordDeploymentSucceededOutboxEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class DeploymentIntegrationOutboxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('environments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('builds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('environment_id')->nullable();
            $table->string('status');
            $table->string('revision', 64)->nullable();
            $table->string('release_name')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('deployment_succeeded_outbox_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('event_type', 100);
            $table->unsignedSmallInteger('event_version')->default(1);
            $table->unsignedBigInteger('source_build_id');
            $table->unsignedBigInteger('source_project_id');
            $table->unsignedBigInteger('source_environment_id');
            $table->json('payload');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
            $table->unique(['event_type', 'source_build_id']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('deployment_succeeded_outbox_events');
        Schema::dropIfExists('builds');
        Schema::dropIfExists('environments');

        parent::tearDown();
    }

    public function test_success_event_is_versioned_idempotent_and_excludes_unneeded_deployment_details(): void
    {
        $environmentId = DB::table('environments')->insertGetId([
            'project_id' => 23,
            'name' => 'Production',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $buildId = DB::table('builds')->insertGetId([
            'environment_id' => $environmentId,
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat('a', 40),
            'release_name' => 'should-not-override-a-revision',
            'built_at' => now()->subMinute(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(RecordDeploymentSucceededOutboxEvent::class);
        $event = $service->record(Build::query()->findOrFail($buildId));
        $duplicate = $service->record(Build::query()->findOrFail($buildId));

        $this->assertInstanceOf(DeploymentSucceededOutboxEvent::class, $event);
        $this->assertSame($event->getKey(), $duplicate?->getKey());
        $this->assertSame(1, DeploymentSucceededOutboxEvent::query()->count());
        $this->assertSame(1, $event->event_version);
        $this->assertSame((string) $buildId, (string) $event->source_build_id);
        $this->assertSame((string) $environmentId, (string) $event->source_environment_id);
        $this->assertSame('deployer.deployment_succeeded', $event->event_type);
        $this->assertTrue(Str::isUuid($event->payload['deployment_id']));
        $this->assertSame(str_repeat('a', 40), $event->payload['revision']);
        $this->assertSame(str_repeat('a', 40), $event->payload['version']);
        $this->assertArrayNotHasKey('commit_message', $event->payload);
        $this->assertArrayNotHasKey('environment_payload', $event->payload);
        $this->assertSame('pending', $event->status);
    }

    public function test_outbox_event_rolls_back_with_the_source_deployment_transaction(): void
    {
        $environmentId = DB::table('environments')->insertGetId([
            'project_id' => 23,
            'name' => 'Production',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $buildId = DB::table('builds')->insertGetId([
            'environment_id' => $environmentId,
            'status' => Build::STATUS_DEPLOYING,
            'revision' => str_repeat('b', 40),
            'built_at' => null,
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::transaction(function () use ($buildId): void {
                DB::table('builds')->where('id', $buildId)->update([
                    'status' => Build::STATUS_SUCCEEDED,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
                app(RecordDeploymentSucceededOutboxEvent::class)->record(Build::query()->findOrFail($buildId));
                throw new RuntimeException('Simulate a later failure in the source transaction.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulate a later failure in the source transaction.', $exception->getMessage());
        }

        $this->assertSame(Build::STATUS_DEPLOYING, DB::table('builds')->where('id', $buildId)->value('status'));
        $this->assertSame(0, DeploymentSucceededOutboxEvent::query()->count());
    }
}
