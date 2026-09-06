<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApplicationConfigurationBindings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApplicationConfigurationBindingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owned_bindings_resolve_without_reading_secret_values_or_writing_state(): void
    {
        $owner = User::factory()->create();
        $project = $owner->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $owner->id]);
        $server = $owner->servers()->create(['name' => 'Test']);
        $website = $owner->websites()->create(['server_id' => $server->id, 'name' => 'Test', 'url' => 'test.example', 'description' => 'Test', 'environment' => '']);
        $environment = $project->environments()->create(['name' => 'Staging', 'slug' => 'staging', 'type' => 'staging']);
        $secret = $environment->variables()->create(['key' => 'TOKEN', 'value' => 'private-token', 'is_secret' => true, 'scope' => 'runtime', 'current_version' => 3, 'updated_by' => $owner->id]);
        // Invalid ciphertext would fail if resolution tried to decrypt the value.
        DB::table('environment_variables')->where('id', $secret->id)->update(['value' => 'unreadable-ciphertext']);
        $document = ['environments' => [['placement' => 'site', 'variables' => ['TOKEN' => ['secret_ref' => 'token', 'scope' => 'runtime']]]]];
        $bindings = ['placements' => ['site' => $website->id], 'secrets' => ['token' => $secret->id]];
        $resolved = app(ApplicationConfigurationBindings::class)->resolve($project, $owner, $document, $bindings);
        $this->assertSame(['website_id' => $website->id, 'server_id' => $server->id], $resolved['placements']['site']);
        $this->assertSame(['variable_id' => $secret->id, 'version' => 3], $resolved['secrets']['token']);
        $this->assertStringNotContainsString('ciphertext', json_encode($resolved));
        $this->assertDatabaseCount('environment_variables', 1);
        $this->assertDatabaseCount('environments', 1);

        $document['environments'][0]['variables']['TOKEN']['scope'] = 'all';
        $this->expectException(ValidationException::class);
        app(ApplicationConfigurationBindings::class)->resolve($project, $owner, $document, $bindings);
    }

    public function test_direct_call_rejects_another_workspace(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = $owner->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $owner->id]);
        $this->expectException(AuthorizationException::class);
        app(ApplicationConfigurationBindings::class)->resolve($project, $other, ['environments' => []], []);
    }

    public function test_missing_placement_returns_a_safe_error_without_writes(): void
    {
        $owner = User::factory()->create();
        $project = $owner->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $owner->id]);
        try {
            app(ApplicationConfigurationBindings::class)->resolve($project, $owner, ['environments' => [['placement' => 'private-value']]], []);
            $this->fail('Missing binding accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringNotContainsString('private-value', json_encode($exception->errors()));
        }
        $this->assertDatabaseCount('environments', 0);
    }

    public function test_malformed_bindings_are_rejected_by_the_service_without_echoing_input(): void
    {
        $owner = User::factory()->create();
        $project = $owner->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $owner->id]);
        foreach ([
            ['private-input' => []], ['placements' => 'private-input'],
            ['placements' => ['site' => '1']], ['secrets' => ['token' => true]],
            ['repositories' => ['app' => -1]], ['secrets' => [0 => 1]],
            ['secrets' => ['private.input' => 1]], ['placements' => ['site' => ['private-input']]],
            ['secrets' => array_fill(0, 2001, 1)],
        ] as $bindings) {
            try {
                app(ApplicationConfigurationBindings::class)->resolve($project, $owner, ['environments' => []], $bindings);
                $this->fail('Malformed binding accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('bindings', $exception->errors());
                $this->assertStringNotContainsString('private-input', json_encode($exception->errors()));
            }
        }
        $this->assertDatabaseCount('configuration_reviews', 0);
        $this->assertDatabaseCount('environments', 0);
    }
}
