<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProjectResourceLinkProvider;
use App\Core\Data\Projects\ProjectResourceCandidate;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Services\ProjectResourceLinkRegistry;
use App\Core\Services\ProjectResourceLinks;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ProjectResourceLinksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('core')->create('project_products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_id', 26);
            $table->string('product', 24);
            $table->string('status', 24)->default('inactive');
            $table->string('requested_by_user_id', 26)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'product']);
        });
        Schema::connection('core')->create('project_environments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_id', 26);
            $table->string('name');
            $table->string('slug');
            $table->string('environment_type');
            $table->string('status');
            $table->timestamps();
        });
        Schema::connection('core')->create('project_resources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_id', 26);
            $table->string('environment_id', 26)->nullable();
            $table->string('product', 24);
            $table->string('resource_type', 100);
            $table->string('resource_id', 191);
            $table->string('name')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamp('mapped_at')->nullable();
            $table->timestamps();
            $table->unique(['product', 'resource_type', 'resource_id']);
        });
    }

    protected function tearDown(): void
    {
        Schema::connection('core')->dropIfExists('project_products');
        Schema::connection('core')->dropIfExists('project_resources');
        Schema::connection('core')->dropIfExists('project_environments');

        parent::tearDown();
    }

    public function test_an_existing_authorized_resource_is_linked_to_the_canonical_project_once(): void
    {
        $registry = new ProjectResourceLinkRegistry;
        $registry->register('monitor', new class implements ProjectResourceLinkProvider
        {
            public function candidates(PlatformUser $user): array
            {
                return [new ProjectResourceCandidate('42', 'application', 'Status API', 'Acme workspace')];
            }

            public function candidate(PlatformUser $user, string $resourceId): ?ProjectResourceCandidate
            {
                return $resourceId === 'application:42'
                    ? new ProjectResourceCandidate('42', 'application', 'Status API', 'Acme workspace')
                    : null;
            }
        });
        $links = new ProjectResourceLinks($registry);
        $user = new PlatformUser;
        $user->setAttribute('id', '01J8AA00000000000000000000');
        $project = new Project;
        $project->setAttribute('id', '01J8AA00000000000000000001');

        $resource = $links->link($user, $project, 'monitor', 'application:42');

        $this->assertNotNull($resource);
        $this->assertSame('application', $resource->resource_type);
        $this->assertSame('42', $resource->resource_id);
        $this->assertSame('Status API', $resource->name);
        $this->assertSame($project->getKey(), $resource->project_id);
        $this->assertDatabaseHas('project_products', [
            'project_id' => $project->getKey(),
            'product' => 'monitor',
            'status' => 'active',
            'requested_by_user_id' => $user->getKey(),
        ], 'core');
        $this->assertNull($links->link($user, $project, 'monitor', 'application:42'));
        $this->assertSame(1, DB::connection('core')->table('project_resources')->count());
    }

    public function test_linking_an_app_environment_requires_and_persists_an_explicit_canonical_mapping(): void
    {
        $registry = new ProjectResourceLinkRegistry;
        $registry->register('monitor', new class implements ProjectResourceLinkProvider
        {
            public function candidates(PlatformUser $user): array
            {
                return [new ProjectResourceCandidate('42', 'environment', 'Production', 'Status API')];
            }

            public function candidate(PlatformUser $user, string $selectionKey): ?ProjectResourceCandidate
            {
                return $selectionKey === 'environment:42'
                    ? new ProjectResourceCandidate('42', 'environment', 'Production', 'Status API')
                    : null;
            }
        });

        $links = new ProjectResourceLinks($registry);
        $user = new PlatformUser;
        $user->setAttribute('id', '01J8AA00000000000000000000');
        $project = new Project;
        $project->setAttribute('id', '01J8AA00000000000000000001');
        $canonicalEnvironmentId = '01J8AA00000000000000000002';
        DB::connection('core')->table('project_environments')->insert([
            'id' => $canonicalEnvironmentId,
            'project_id' => $project->getKey(),
            'name' => 'Production',
            'slug' => 'production',
            'environment_type' => 'production',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $links->link($user, $project, 'monitor', 'environment:42');
            $this->fail('An app environment must not link without a canonical project environment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('environment_id', $exception->errors());
        }

        $foreignProjectId = '01J8AA00000000000000000003';
        $foreignEnvironmentId = '01J8AA00000000000000000004';
        DB::connection('core')->table('project_environments')->insert([
            'id' => $foreignEnvironmentId,
            'project_id' => $foreignProjectId,
            'name' => 'Production',
            'slug' => 'production',
            'environment_type' => 'production',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertNull($links->link($user, $project, 'monitor', 'environment:42', $foreignEnvironmentId));

        $resource = $links->link($user, $project, 'monitor', 'environment:42', $canonicalEnvironmentId);

        $this->assertNotNull($resource);
        $this->assertSame('environment', $resource->resource_type);
        $this->assertSame('42', $resource->resource_id);
        $this->assertSame($canonicalEnvironmentId, $resource->environment_id);
        $this->assertSame($project->getKey(), $resource->project_id);
    }

    public function test_candidates_are_only_loaded_for_the_requested_products(): void
    {
        $registry = new ProjectResourceLinkRegistry;
        $called = (object) ['deployer' => false, 'analytics' => false];
        foreach (['deployer', 'analytics'] as $product) {
            $registry->register($product, new class($called, $product) implements ProjectResourceLinkProvider
            {
                public function __construct(private object $called, private string $product) {}

                public function candidates(PlatformUser $user): array
                {
                    $this->called->{$this->product} = true;

                    return [new ProjectResourceCandidate('7', 'site', 'Main site')];
                }

                public function candidate(PlatformUser $user, string $selectionKey): ?ProjectResourceCandidate
                {
                    return null;
                }
            });
        }

        $candidates = (new ProjectResourceLinks($registry))->candidates(new PlatformUser, ['analytics']);

        $this->assertFalse($called->deployer);
        $this->assertTrue($called->analytics);
        $this->assertSame(['analytics'], $candidates->keys()->all());
    }
}
