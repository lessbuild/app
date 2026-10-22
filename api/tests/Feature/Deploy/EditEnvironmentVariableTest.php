<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Models\EnvironmentVariable;
use App\Models\PendingVariableChange;
use App\Models\Project;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class EditEnvironmentVariableTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * A variable's value, scope and rotation date change as a new version; a secret left empty keeps its value,
     * and only stops being secret with a new value; another environment's variable can't be edited; where changes
     * need approval, editing asks for it.
     *
     * @return void
     */
    public function test_a_variable_is_edited_as_a_new_version(): void
    {
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $variable = fn (string $key, string $value, bool $secret): EnvironmentVariable => tap(new EnvironmentVariable, fn (EnvironmentVariable $variable) => $variable->forceFill(['environment_id' => $production->id, 'key' => $key, 'value' => $value, 'is_secret' => $secret, 'scope' => 'runtime', 'current_version' => 1])->save());
        $level = $variable('LOG_LEVEL', 'debug', false);
        $stripe = $variable('STRIPE_SECRET', 'sk_live_1', true);
        $base = "/api/app/projects/{$project->id}/deploy/environments/{$production->id}/variables";

        $this->actingAs($owner)->putJson("{$base}/{$level->id}", ['value' => 'warning', 'scope' => 'all'])->assertOk()->assertJsonPath('message', 'LOG_LEVEL saved.');
        $this->assertSame(['warning', 'all', 2, false], [$level->refresh()->value, $level->scope, $level->current_version, $level->is_secret]);

        $this->actingAs($owner)->putJson("{$base}/{$stripe->id}", ['value' => '', 'scope' => 'runtime', 'is_secret' => '1'])->assertOk();
        $this->assertSame(['sk_live_1', 2, true], [$stripe->refresh()->value, $stripe->current_version, $stripe->is_secret], 'Left empty, the secret keeps its value.');
        $this->actingAs($owner)->putJson("{$base}/{$stripe->id}", ['value' => '', 'scope' => 'runtime'])->assertJsonValidationErrors('value');
        $this->actingAs($owner)->putJson("{$base}/{$stripe->id}", ['value' => 'sk_live_2', 'scope' => 'runtime', 'is_secret' => '1'])->assertOk();
        $this->assertSame('sk_live_2', $stripe->refresh()->value);

        $other = Project::factory()->create()->environments()->firstOrFail();
        $foreign = tap(new EnvironmentVariable, fn (EnvironmentVariable $variable) => $variable->forceFill(['environment_id' => $other->id, 'key' => 'APP_KEY', 'value' => 'x', 'is_secret' => true, 'scope' => 'runtime', 'current_version' => 1])->save());
        $this->actingAs($owner)->putJson("{$base}/{$foreign->id}", ['value' => 'y', 'scope' => 'runtime'])->assertNotFound();

        $production->forceFill(['require_variable_approval' => true])->save();
        $this->actingAs($owner)->putJson("{$base}/{$level->id}", ['value' => 'error', 'scope' => 'all'])->assertOk()->assertJsonPath('message', 'LOG_LEVEL is waiting for someone else to approve it.');
        $this->assertSame('warning', $level->refresh()->value);
        $this->assertSame(1, PendingVariableChange::query()->count());
    }
}
