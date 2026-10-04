<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Actions\ApiTokens\CreateApiToken;
use App\Data\ApiTokens\CreateApiTokenData;
use App\Enums\AccountRole;
use App\Enums\ApiScope;
use App\Models\PendingVariableChange;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class VariableApprovalTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that with two-person approval on, variable changes (from the web or the API) wait, the person who asked
     * can't approve their own change, and someone else's approval applies it; rejections apply nothing.
     *
     * @return void
     */
    public function test_variable_changes_wait_for_a_second_person(): void
    {
        $this->withoutMiddleware(RequirePassword::class);
        $project = Project::factory()->withServices(['deploy'])->create();
        $owner = $this->ownerOf($project);
        $colleague = User::factory()->create();
        $this->addMember($project, $colleague, AccountRole::Admin);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $base = "/api/app/projects/{$project->id}/deploy/environments/{$production->id}";

        $this->actingAs($owner)->putJson("{$base}/controls", ['require_variable_approval' => '1'])->assertSuccessful();
        $this->assertTrue($production->refresh()->require_variable_approval);

        $this->actingAs($owner)->postJson("{$base}/variables", ['key' => 'STRIPE_SECRET', 'value' => 'sk_live_123', 'scope' => 'runtime', 'is_secret' => '1'])->assertSuccessful()->assertSuccessful();
        $this->assertSame(0, $production->variables()->count());
        $change = PendingVariableChange::query()->sole();
        $this->assertSame(['Set STRIPE_SECRET', 'sk_live_123'], [$change->summary, $change->payload['value']]);
        $this->assertStringNotContainsString('sk_live_123', (string) $change->getRawOriginal('payload'));

        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('pendingChanges.0.mine', true);
        $this->actingAs($owner)->postJson("{$base}/variable-changes/{$change->id}", ['decision' => 'approve'])->assertJsonValidationErrors('change');
        $this->actingAs($colleague)->getJson($base)->assertOk()->assertJsonPath('pendingChanges.0.mine', false);
        $this->actingAs($colleague)->postJson("{$base}/variable-changes/{$change->id}", ['decision' => 'approve'])->assertSuccessful();
        $this->assertSame('sk_live_123', $production->variables()->where('key', 'STRIPE_SECRET')->sole()->value);
        $this->actingAs($colleague)->postJson("{$base}/variable-changes/{$change->id}", ['decision' => 'approve'])->assertStatus(409);

        // Removing waits too; a rejection leaves it.
        $variable = $production->variables()->sole();
        $this->actingAs($owner)->deleteJson("{$base}/variables/{$variable->id}")->assertSuccessful();
        $removal = PendingVariableChange::query()->where('kind', 'delete')->sole();
        $this->actingAs($colleague)->postJson("{$base}/variable-changes/{$removal->id}", ['decision' => 'reject'])->assertSuccessful();
        $this->assertSame(1, $production->variables()->count());

        // The API holds a replacement for approval rather than applying it.
        $token = app(CreateApiToken::class)->handle($owner, $project->account, new CreateApiTokenData('ci', [ApiScope::DeployRead, ApiScope::from('deploy:write')], 30))->plainText;
        $this->withToken($token)->putJson("/api/v1/environments/{$production->id}/variables", ['variables' => "APP_ENV=production\n"])
            ->assertStatus(202)->assertJsonPath('data.status', 'pending_approval');
        $this->assertSame(1, $production->variables()->count());
    }
}
