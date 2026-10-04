<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\SelectionKind;
use App\Models\BillingSelection;
use App\Models\Project;
use App\Models\SecurityBlock;
use App\Models\Server;
use App\Models\Website;
use App\Services\Security\AttackWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class AttackBlockingTest extends TestCase
{
    use InfrastructureHelpers, MonitoringHelpers {
        MonitoringHelpers::ownerOf insteadof InfrastructureHelpers;
    }
    use RefreshDatabase;

    /**
     * Check attackers are picked out and blocked, allowlisted, private and Cloudflare addresses never are, blocks
     * lift on time or by hand, and the plan and setting are respected.
     *
     * @return void
     */
    public function test_attackers_are_blocked_and_blocks_lift(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['security', 'infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $server = Server::factory()->create(['account_id' => $project->account_id]);
        Website::factory()->create(['account_id' => $project->account_id, 'environment_id' => $project->environments()->firstOrFail()->id, 'server_id' => $server->id, 'deployment_slug' => 'shop']);
        $summary = "203.0.113.7 30 25 0 0\n198.51.100.9 14 0 12 12\n192.0.2.44 3100 0 0 5\n10.0.0.5 500 90 0 0\n104.16.1.1 400 40 0 0\n203.0.113.99 60 0 0 1\n198.51.100.200 40 30 0 0\n";
        $watch = app(AttackWatch::class);

        $this->assertSame(0, $watch->run(), 'The free plan doesn’t block.');
        $this->assertSame([], $this->shell->ran);

        (new BillingSelection)->forceFill(['account_id' => $project->account_id, 'service' => 'security', 'kind' => SelectionKind::Tier, 'item_key' => 'pro'])->save();
        $this->actingAs($owner)->putJson("/api/app/projects/{$project->id}/security/attacks/settings", ['autoblock' => '1', 'block_hours' => 12, 'allowlist' => "198.51.100.0/24\n"])->assertSuccessful();
        $this->shell->reply($summary);
        $this->assertSame(2, $watch->run());
        $this->assertStringContainsString('/var/log/caddy/shop.access.log', $this->shell->ran[0]['command']);
        $this->assertStringContainsString("ufw insert 1 deny from '203.0.113.7'", $this->shell->ran[1]['command']);
        $blocks = SecurityBlock::query()->orderBy('id')->get();
        [$bruteForce, $flood] = [$blocks->where('reason', 'brute-force')->firstOrFail(), $blocks->where('reason', 'flood')->firstOrFail()];
        $this->assertSame([['203.0.113.7', 'brute-force', 25], ['192.0.2.44', 'flood', 3100]], $blocks->map(fn (SecurityBlock $block): array => [$block->ip, $block->reason, $block->hits])->all(),
            '198.51.100.x is allowlisted; 10.0.0.5 is private; 104.16.1.1 is Cloudflare; 203.0.113.99 did nothing wrong.');
        $this->assertTrue($bruteForce->expires_at->between(now()->addHours(11), now()->addHours(13)));

        $this->shell->reply($summary);
        $this->assertSame(0, $watch->run(), 'Already blocked.');

        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/security/attacks")->assertOk()->assertJsonFragment(['ip' => '203.0.113.7', 'reason' => __('Repeated failed sign-ins')]);
        $this->actingAs($owner)->deleteJson("/api/app/projects/{$project->id}/security/attacks/{$bruteForce->id}")->assertSuccessful();
        $this->assertNotNull($bruteForce->refresh()->lifted_at);
        $this->assertSame($owner->id, $bruteForce->lifted_by);
        $this->assertStringContainsString("ufw delete deny from '203.0.113.7'", collect($this->shell->ran)->last()['command'] ?? '');

        $this->travel(13)->hours();
        $this->shell->reply('');
        $watch->run();
        $this->assertNotNull($flood->refresh()->lifted_at, 'Expired blocks lift on their own.');

        $this->actingAs($owner)->putJson("/api/app/projects/{$project->id}/security/attacks/settings", ['block_hours' => 24, 'allowlist' => 'not-an-ip'])->assertJsonValidationErrors('allowlist');
    }
}
