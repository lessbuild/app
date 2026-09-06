<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApplicationConfigurationVariables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApplicationConfigurationVariablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_copy_is_encrypted_versioned_and_idempotent_and_rejects_stale_sources(): void
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $sourceEnvironment = $project->environments()->create(['name' => 'Source', 'slug' => 'source', 'type' => 'staging']);
        $targetEnvironment = $project->environments()->create(['name' => 'Target', 'slug' => 'target', 'type' => 'staging']);
        $source = $sourceEnvironment->variables()->create(['key' => 'TOKEN', 'value' => 'private-value', 'is_secret' => true, 'scope' => 'all', 'current_version' => 1, 'updated_by' => $user->id]);
        $service = app(ApplicationConfigurationVariables::class);
        $target = $service->synchronize($targetEnvironment, 'API_TOKEN', $source->id, 1, 'runtime', $user);
        $this->assertSame('private-value', $target->value);
        $this->assertStringNotContainsString('private-value', $target->getRawOriginal('value'));
        $this->assertSame('private-value', $target->versions()->first()->value);
        $this->assertSame($target->id, $service->synchronize($targetEnvironment, 'API_TOKEN', $source->id, 1, 'runtime', $user)->id);
        $this->assertSame(1, $target->versions()->count());
        $source->update(['value' => 'rotated-value', 'current_version' => 2]);
        try {
            $service->synchronize($targetEnvironment, 'API_TOKEN', $source->id, 1, 'runtime', $user);
            $this->fail('Stale source accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bindings', $exception->errors());
        }
        $this->assertSame('private-value', $target->fresh()->value);
        $updated = $service->synchronize($targetEnvironment, 'API_TOKEN', $source->id, 2, 'runtime', $user);
        $this->assertSame(2, $updated->current_version);
        $this->assertSame(2, $updated->versions()->count());
    }
}
