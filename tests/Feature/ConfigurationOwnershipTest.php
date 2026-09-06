<?php

namespace Tests\Feature;

use App\Models\ConfigurationOwnership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigurationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_resource_cannot_be_claimed_under_two_logical_names(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $record = ['project_id' => $project->id, 'environment_slug' => 'staging', 'kind' => 'processes', 'logical_name' => 'worker', 'resource_id' => 1];
        ConfigurationOwnership::query()->create($record);
        $this->expectException(QueryException::class);
        ConfigurationOwnership::query()->create([...$record, 'logical_name' => 'other']);
    }

    public function test_a_logical_name_cannot_claim_two_resources(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $record = ['project_id' => $project->id, 'environment_slug' => 'staging', 'kind' => 'processes', 'logical_name' => 'worker', 'resource_id' => 1];
        ConfigurationOwnership::query()->create($record);
        $this->expectException(QueryException::class);
        ConfigurationOwnership::query()->create([...$record, 'resource_id' => 2]);
    }
}
