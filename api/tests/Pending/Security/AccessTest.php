<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\AccountRole;
use App\Enums\SelectionKind;
use App\Models\ApiToken;
use App\Models\BillingSelection;
use App\Models\Membership;
use App\Models\Project;
use App\Models\SecurityAccessReview;
use App\Models\SecurityFinding;
use App\Models\Server;
use App\Models\ServerSshGrant;
use App\Models\User;
use App\Models\UserSshKey;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class AccessTest extends TestCase
{
    use InfrastructureHelpers, MonitoringHelpers {
        MonitoringHelpers::ownerOf insteadof InfrastructureHelpers;
    }
    use RefreshDatabase;

    /**
     * A real ed25519 public key.
     *
     * @var string
     */
    private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl laptop';

    /**
     * Check SSH keys on profiles, access given and taken away (including when someone leaves), and access reviews
     * that find problems, remove what's ticked and keep a record.
     *
     * @return void
     */
    public function test_ssh_access_and_access_reviews(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['security', 'infrastructure'])->create();
        (new BillingSelection)->forceFill(['account_id' => $project->account_id, 'service' => 'security', 'kind' => SelectionKind::Tier, 'item_key' => 'team'])->save();
        $owner = $this->ownerOf($project);
        $owner->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_secret' => 'x'])->save();
        $dev = User::factory()->create(['name' => 'Dev Person']);
        $this->addMember($project, $dev, AccountRole::Member);
        $server = Server::factory()->create(['account_id' => $project->account_id, 'name' => 'deployer']);
        Website::factory()->create(['account_id' => $project->account_id, 'environment_id' => $project->environments()->firstOrFail()->id, 'server_id' => $server->id]);
        $page = "/projects/{$project->id}/security";

        $this->assertStringStartsWith('SHA256:', UserSshKey::parse(self::KEY)['fingerprint'] ?? '');
        $this->assertNull(UserSshKey::parse('ssh-ed25519 notbase64!!'));
        $this->assertNull(UserSshKey::parse('-----BEGIN OPENSSH PRIVATE KEY-----'));

        $this->actingAs($dev)->withSession(['auth.password_confirmed_at' => time()])->post('/settings/ssh-keys', ['name' => 'Laptop', 'public_key' => self::KEY])->assertRedirect('/settings/security');
        $this->actingAs($dev)->withSession(['auth.password_confirmed_at' => time()])->post('/settings/ssh-keys', ['name' => 'Again', 'public_key' => self::KEY])->assertSessionHasErrors('public_key');
        $this->assertSame('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl', UserSshKey::query()->sole()->public_key, 'The comment is dropped.');

        $this->actingAs($owner)->post("{$page}/servers/{$server->id}/ssh", ['user_id' => $dev->id])->assertRedirect("{$page}/servers");
        $grant = ServerSshGrant::query()->sole();
        $this->assertSame('active', $grant->status);
        $install = collect($this->shell->ran)->last()['command'] ?? '';
        $this->assertStringContainsString('/home/deployer/.ssh/authorized_keys', $install);
        $this->assertStringContainsString("AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl buildpusher-user:{$dev->id}", $install);
        $this->actingAs($owner)->get("{$page}/servers")->assertOk()->assertSee('Dev Person')->assertSee(__('Remove access'));

        // Access review: the scan finds the review due, the member without two-factor and an idle token.
        $token = new ApiToken;
        $token->forceFill(['tokenable_type' => User::class, 'tokenable_id' => $dev->id, 'account_id' => $project->account_id, 'name' => 'Old script', 'token' => hash('sha256', 'secret'), 'abilities' => ['deploy:read'], 'created_at' => now()->subDays(200)])->save();
        $this->actingAs($owner)->post("{$page}/scans", ['kind' => 'access'])->assertRedirect();
        $titles = SecurityFinding::query()->where('source', 'access')->where('status', 'open')->pluck('title')->all();
        $this->assertContains('Access hasn’t been reviewed yet', $titles);
        $this->assertContains('Dev Person signs in without two-factor authentication', $titles);
        $this->assertContains('The API token “Old script” hasn’t been used in 90 days', $titles);
        $this->assertNotContains(sprintf('%s signs in without two-factor authentication', $owner->name), $titles);

        $this->actingAs($owner)->get("{$page}/access")->assertOk()->assertSee('Dev Person')->assertSee('Old script');
        $membership = Membership::query()->where('user_id', $dev->id)->sole();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->post("{$page}/access/reviews", ['members' => [$membership->id], 'tokens' => [$token->id]])->assertRedirect("{$page}/access");
        $review = SecurityAccessReview::query()->sole();
        $this->assertSame(['Member Dev Person', 'API token Old script'], $review->summary['removed']);
        $this->assertFalse(Membership::query()->whereKey($membership->id)->exists());
        $this->assertFalse(ApiToken::query()->whereKey($token->id)->exists());
        $this->assertFalse(ServerSshGrant::query()->exists(), 'Leaving the account took their SSH access away.');
        $this->assertStringContainsString("grep -v -E ' buildpusher-user:{$dev->id}\$'", collect($this->shell->ran)->last()['command'] ?? '');
        $this->assertSame([], SecurityFinding::query()->where('source', 'access')->where('status', 'open')->pluck('title')->all(), 'The review cleared the findings.');
    }
}
