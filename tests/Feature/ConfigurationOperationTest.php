<?php

namespace Tests\Feature;

use App\Models\ConfigurationApplication;
use App\Models\ConfigurationReview;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigurationOperationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operation_payload_is_private_and_identity_is_unique_per_application(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $review = ConfigurationReview::create(['project_id' => $project->id, 'requested_by' => $user->id, 'document' => 'test', 'bindings' => [], 'summary' => [], 'expires_at' => now()->addMinute()]);
        $application = ConfigurationApplication::create(['configuration_review_id' => $review->id, 'status' => 'locally_applied']);
        $attributes = ['environment_slug' => 'staging', 'kind' => 'deploy', 'payload' => ['value' => 'private-snapshot']];
        $operation = $application->operations()->create($attributes)->fresh();
        $this->assertSame('pending', $operation->status);
        $this->assertSame(['value' => 'private-snapshot'], $operation->payload);
        $this->assertStringNotContainsString('private-snapshot', $operation->getRawOriginal('payload'));
        $this->assertArrayNotHasKey('payload', $operation->toArray());
        $this->assertSame($application->id, $operation->application->id);
        $this->expectException(QueryException::class);
        $application->operations()->create($attributes);
    }
}
