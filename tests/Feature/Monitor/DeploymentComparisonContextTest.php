<?php

namespace Tests\Feature\Monitor;

use App\Modules\Monitor\Models\Deployment;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\ReleaseMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DeploymentComparisonContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->seedRecords();
    }

    protected function tearDown(): void
    {
        foreach (['incidents', 'monitors', 'alert_rules', 'deployments', 'releases', 'environments', 'applications', 'workspaces'] as $table) {
            Schema::connection('monitor')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_it_limits_deployment_and_incident_context_to_the_same_service_environment_and_window(): void
    {
        $workspace = (new Workspace)->forceFill(['id' => 1, 'name' => 'Workspace', 'slug' => 'workspace']);
        $deployment = Deployment::query()->findOrFail(1);
        $metrics = app(ReleaseMetrics::class);
        $from = CarbonImmutable::parse('2026-04-02 11:00:00', 'UTC');
        $until = CarbonImmutable::parse('2026-04-02 13:00:00', 'UTC');

        $nearbyDeployments = $metrics->otherDeploymentsInWindow($workspace, $deployment, $from, $until);
        $incidents = $metrics->incidentsOverlappingWindow($workspace, $deployment, $from, $until);

        $this->assertSame([2], $nearbyDeployments->modelKeys());
        $this->assertSame([1, 3], $incidents->modelKeys());
    }

    private function createTables(): void
    {
        foreach (['incidents', 'monitors', 'alert_rules', 'deployments', 'releases', 'environments', 'applications', 'workspaces'] as $table) {
            Schema::connection('monitor')->dropIfExists($table);
        }

        Schema::connection('monitor')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
        });
        Schema::connection('monitor')->create('applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::connection('monitor')->create('environments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::connection('monitor')->create('releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id');
            $table->string('service');
            $table->string('service_namespace')->nullable();
            $table->string('version');
            $table->string('service_hash');
            $table->string('version_hash')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('deployments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id');
            $table->foreignId('release_id');
            $table->timestamp('deployed_at', 6);
            $table->timestamps(6);
        });
        Schema::connection('monitor')->create('alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id');
            $table->softDeletes();
        });
        Schema::connection('monitor')->create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id');
            $table->softDeletes();
        });
        Schema::connection('monitor')->create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_rule_id')->nullable();
            $table->foreignId('monitor_id')->nullable();
            $table->string('title');
            $table->string('status');
            $table->timestamp('opened_at', 6);
            $table->timestamp('resolved_at', 6)->nullable();
        });
    }

    private function seedRecords(): void
    {
        DB::connection('monitor')->table('workspaces')->insert([
            ['id' => 1, 'name' => 'Workspace', 'slug' => 'workspace'],
            ['id' => 2, 'name' => 'Other workspace', 'slug' => 'other-workspace'],
        ]);
        DB::connection('monitor')->table('applications')->insert([
            ['id' => 1, 'workspace_id' => 1, 'name' => 'App', 'deleted_at' => null],
            ['id' => 2, 'workspace_id' => 2, 'name' => 'Other app', 'deleted_at' => null],
        ]);
        DB::connection('monitor')->table('environments')->insert([
            ['id' => 1, 'application_id' => 1, 'name' => 'Production', 'deleted_at' => null],
            ['id' => 2, 'application_id' => 1, 'name' => 'Staging', 'deleted_at' => null],
            ['id' => 3, 'application_id' => 2, 'name' => 'Production', 'deleted_at' => null],
        ]);
        DB::connection('monitor')->table('releases')->insert([
            ['id' => 1, 'application_id' => 1, 'service' => 'web', 'version' => 'v2', 'service_hash' => 'web', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'application_id' => 1, 'service' => 'web', 'version' => 'v1', 'service_hash' => 'web', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'application_id' => 1, 'service' => 'worker', 'version' => 'v1', 'service_hash' => 'worker', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'application_id' => 2, 'service' => 'web', 'version' => 'v1', 'service_hash' => 'web', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('monitor')->table('deployments')->insert([
            ['id' => 1, 'environment_id' => 1, 'release_id' => 1, 'deployed_at' => '2026-04-02 12:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'environment_id' => 1, 'release_id' => 2, 'deployed_at' => '2026-04-02 11:45:00', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'environment_id' => 1, 'release_id' => 3, 'deployed_at' => '2026-04-02 11:50:00', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'environment_id' => 2, 'release_id' => 2, 'deployed_at' => '2026-04-02 11:55:00', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'environment_id' => 3, 'release_id' => 4, 'deployed_at' => '2026-04-02 11:55:00', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'environment_id' => 1, 'release_id' => 2, 'deployed_at' => '2026-04-02 10:59:59', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 7, 'environment_id' => 1, 'release_id' => 2, 'deployed_at' => '2026-04-02 13:00:00', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('monitor')->table('alert_rules')->insert([
            ['id' => 1, 'environment_id' => 1, 'deleted_at' => null],
            ['id' => 2, 'environment_id' => 1, 'deleted_at' => null],
            ['id' => 3, 'environment_id' => 1, 'deleted_at' => null],
            ['id' => 4, 'environment_id' => 1, 'deleted_at' => null],
            ['id' => 5, 'environment_id' => 2, 'deleted_at' => null],
            ['id' => 6, 'environment_id' => 3, 'deleted_at' => null],
        ]);
        DB::connection('monitor')->table('incidents')->insert([
            ['id' => 1, 'alert_rule_id' => 1, 'monitor_id' => null, 'title' => 'Open incident', 'status' => 'open', 'opened_at' => '2026-04-02 11:30:00', 'resolved_at' => null],
            ['id' => 2, 'alert_rule_id' => 2, 'monitor_id' => null, 'title' => 'Earlier incident', 'status' => 'resolved', 'opened_at' => '2026-04-02 10:30:00', 'resolved_at' => '2026-04-02 10:45:00'],
            ['id' => 3, 'alert_rule_id' => 3, 'monitor_id' => null, 'title' => 'Recovered incident', 'status' => 'resolved', 'opened_at' => '2026-04-02 11:40:00', 'resolved_at' => '2026-04-02 12:10:00'],
            ['id' => 4, 'alert_rule_id' => 4, 'monitor_id' => null, 'title' => 'Later incident', 'status' => 'open', 'opened_at' => '2026-04-02 13:00:00', 'resolved_at' => null],
            ['id' => 5, 'alert_rule_id' => 5, 'monitor_id' => null, 'title' => 'Staging incident', 'status' => 'open', 'opened_at' => '2026-04-02 11:30:00', 'resolved_at' => null],
            ['id' => 6, 'alert_rule_id' => 6, 'monitor_id' => null, 'title' => 'Private incident', 'status' => 'open', 'opened_at' => '2026-04-02 11:30:00', 'resolved_at' => null],
        ]);
    }
}
