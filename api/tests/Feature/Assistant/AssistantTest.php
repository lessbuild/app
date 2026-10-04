<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Actions\ApiTokens\CreateApiToken;
use App\Data\ApiTokens\CreateApiTokenData;
use App\Enums\ApiScope;
use App\Enums\ProviderType;
use App\Models\AssistantConversation;
use App\Models\Build;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Models\Website;
use App\Services\Assistant\AssistantTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use stdClass;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class AssistantTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check the MCP endpoint speaks JSON-RPC, lists only the tools a token's scopes allow, runs them for the token's
     * person, and refuses the rest.
     *
     * @return void
     */
    public function test_ai_tools_read_the_account_over_mcp_within_the_token_scopes(): void
    {
        $project = Project::factory()->withServices(['monitoring', 'deploy'])->create(['name' => 'Shop']);
        $owner = $this->ownerOf($project);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        Issue::factory()->create(['project_id' => $project->id, 'environment_id' => $production->id, 'title' => 'Undefined index: cart', 'occurrences' => 42]);
        Issue::factory()->create(['title' => 'Somebody else’s error']);
        $token = app(CreateApiToken::class)->handle($owner, $project->account, new CreateApiTokenData('claude', [ApiScope::ProjectsRead, ApiScope::MonitoringRead], 30))->plainText;
        $rpc = fn (string $method, array $params = [], ?int $id = 1) => $this->withToken($token)->postJson('/api/mcp', array_filter(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], fn ($value) => $value !== null && $value !== []));

        $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
        $rpc('initialize', ['protocolVersion' => '2025-06-18'])->assertOk()->assertJsonPath('result.protocolVersion', '2025-06-18')->assertJsonPath('result.capabilities.tools.listChanged', false);
        $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
        /** @var list<array{name: string}> $listed */
        $listed = $rpc('tools/list')->assertOk()->json('result.tools');
        $tools = array_column($listed, 'name');
        $this->assertContains('error_issues', $tools);
        $this->assertContains('list_projects', $tools);
        $this->assertNotContains('servers', $tools);
        $this->assertNotContains('recent_deploys', $tools);

        $rpc('tools/call', ['name' => 'list_projects'])->assertOk()->assertJsonPath('result.structuredContent.projects.0.name', 'Shop');
        $issues = $rpc('tools/call', ['name' => 'error_issues', 'arguments' => ['project_id' => $project->id]])->assertOk();
        $this->assertSame(['Undefined index: cart'], array_column($issues->json('result.structuredContent.issues'), 'title'));
        $this->assertStringContainsString('Undefined index: cart', $issues->json('result.content.0.text'));
        $rpc('tools/call', ['name' => 'error_issues', 'arguments' => ['project_id' => 'nope']])->assertOk()->assertJsonPath('result.isError', true);
        $rpc('tools/call', ['name' => 'servers'])->assertOk()->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'This token needs the infrastructure:read scope for servers.');
        $rpc('tools/call', ['name' => 'drop_tables'])->assertOk()->assertJsonPath('error.code', -32602);
        $rpc('resources/list')->assertOk()->assertJsonPath('error.code', -32601);
        $this->withToken($token)->call('POST', '/api/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{nope')->assertOk()->assertJsonPath('error.code', -32700);
    }

    /**
     * Check deploy_impact compares requests, failures and exceptions before and after a deploy went live, and lists
     * the issues first seen after it.
     *
     * @return void
     */
    public function test_deploy_impact_compares_before_and_after_a_deploy(): void
    {
        $project = Project::factory()->withServices(['monitoring', 'deploy', 'infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id]);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id]);
        $repository = Repository::factory()->create(['website_id' => $website->id, 'project_id' => $project->id, 'provider_id' => $github->id, 'environment_id' => $production->id]);
        $live = now()->subHour()->toImmutable();
        $build = Build::factory()->create(['repository_id' => $repository->id, 'environment_id' => $production->id, 'status' => Build::STATUS_SUCCEEDED, 'activated_at' => $live, 'commit_message' => "Add coupons\n\nBody"]);
        foreach (range(1, 4) as $minute) {
            TelemetryEvent::factory()->create(['environment_id' => $production->id, 'occurred_at' => $live->subMinutes($minute * 10)]);
            TelemetryEvent::factory()->create(['environment_id' => $production->id, 'occurred_at' => $live->addMinutes($minute * 10), 'status_code' => $minute <= 2 ? 500 : 200]);
        }
        TelemetryEvent::factory()->create(['environment_id' => $production->id, 'occurred_at' => $live->addMinutes(5), 'type' => 'exception', 'severity' => 'error']);
        Issue::factory()->create(['project_id' => $project->id, 'environment_id' => $production->id, 'title' => 'Coupon is null', 'first_seen_at' => $live->addMinutes(5), 'last_seen_at' => $live->addMinutes(5)]);
        $tools = app(AssistantTools::class);

        $deploys = $tools->call('recent_deploys', [], $project->account, $owner)['deploys'];
        $this->assertSame([$build->id, 'Add coupons'], [$deploys[0]['id'], $deploys[0]['commit']]);
        $impact = $tools->call('deploy_impact', ['build_id' => $build->id, 'hours' => 1], $project->account, $owner);
        $this->assertSame([4, 0.0, 0], [$impact['before']['requests'], $impact['before']['failed_rate_percent'], $impact['before']['exceptions']]);
        $this->assertSame([4, 50.0, 1], [$impact['after']['requests'], $impact['after']['failed_rate_percent'], $impact['after']['exceptions']]);
        $this->assertSame(['Coupon is null'], array_column($impact['new_issues_after'], 'title'));

        $stranger = User::factory()->create();
        $this->expectException(InvalidArgumentException::class);
        $tools->call('deploy_impact', ['build_id' => $build->id], $project->account, $stranger);
    }

    /**
     * Check the in-app assistant answers with Claude calling the tools for the person who asked, keeps the
     * conversation to them, and stops at the account's daily number of questions.
     *
     * @return void
     */
    public function test_the_assistant_answers_in_the_app_with_claude_and_the_tools(): void
    {
        $project = Project::factory()->withServices(['monitoring'])->create(['name' => 'Shop']);
        $owner = $this->ownerOf($project);
        $this->actingAs($owner)->getJson('/api/app/assistant')->assertOk()->assertJsonPath('configured', false)->assertJsonPath('mcpUrl', route('api.mcp'));
        $this->actingAs($owner)->postJson('/api/app/assistant', ['question' => 'Anything broken?'])->assertJsonValidationErrors('question');

        config(['services.anthropic.key' => 'sk-ant-test', 'services.anthropic.questions_per_day' => 2]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => 'Let me check.'], ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'list_projects', 'input' => new stdClass]], 'stop_reason' => 'tool_use'])
            ->push(['content' => [['type' => 'text', 'text' => 'Nothing is broken in **Shop**.']], 'stop_reason' => 'end_turn'])
            ->push(['content' => [['type' => 'text', 'text' => 'Still fine.']], 'stop_reason' => 'end_turn']),
        ]);

        $this->actingAs($owner)->postJson('/api/app/assistant', ['question' => 'Anything broken?'])->assertSuccessful()->assertJsonPath('redirect', fn ($url): bool => str_starts_with((string) $url, '/assistant?conversation='));
        $conversation = AssistantConversation::query()->sole();
        $this->assertSame(['idle', 'Anything broken?'], [$conversation->status, $conversation->title]);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-api-key', 'sk-ant-test') && $request['model'] === config('services.anthropic.model')
            && in_array('deploy_impact', array_column((array) $request['tools'], 'name'), true) && str_contains($request['system'], $project->account->name) && count($request['messages']) === 1);
        Http::assertSent(fn (Request $request): bool => count($request['messages']) === 3 && $request['messages'][2]['content'][0]['type'] === 'tool_result'
            && str_contains($request['messages'][2]['content'][0]['content'], '"name":"Shop"'));
        $this->actingAs($owner)->getJson('/api/app/assistant?conversation='.$conversation->id)->assertOk()->assertJsonPath('conversation.lines.0.text', 'Anything broken?')
            ->assertJsonHasText('<strong>Shop</strong>')->assertJsonLacksText('toolu_1');

        $colleague = User::factory()->create();
        $this->addMember($project, $colleague, \App\Enums\AccountRole::Member);
        $colleague->forceFill(['current_account_id' => $project->account_id])->save();
        $this->actingAs($colleague)->getJson('/api/app/assistant?conversation='.$conversation->id)->assertNotFound();

        $this->actingAs($owner)->postJson('/api/app/assistant', ['question' => 'And now?', 'conversation' => $conversation->id])->assertSuccessful();
        $this->assertCount(4, $conversation->refresh()->transcript());
        $this->actingAs($owner)->postJson('/api/app/assistant', ['question' => 'Third?'])->assertJsonValidationErrors('question');
    }
}
