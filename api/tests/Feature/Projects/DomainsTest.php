<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Contracts\DnsResolver;
use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\Domain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeDnsResolver;
use Tests\TestCase;

final class DomainsTest extends TestCase
{
    use RefreshDatabase;

    private FakeDnsResolver $dns;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for(Account::factory()->withMember($this->owner))->create();
    }

    /**
     * A domain is added, then verified once its TXT record is published.
     */
    public function test_a_domain_is_added_then_verified_from_its_txt_record(): void
    {
        $base = "/api/app/projects/{$this->project->id}/domains";
        $page = "/projects/{$this->project->id}/domains";
        $production = $this->project->environments()->sole();

        $this->actingAs($this->owner)->postJson($base, ['hostname' => 'https://Shop.Example.com/', 'environment_id' => $production->id])->assertOk()->assertJsonPath('redirect', $page);
        $domain = Domain::query()->sole();
        $this->assertSame('shop.example.com', $domain->hostname);
        $this->assertSame($production->id, $domain->environment_id);

        $this->actingAs($this->owner)->getJson($base)->assertOk()
            ->assertJsonPath('domains.0.recordName', '_buildpusher.shop.example.com')->assertJsonPath('domains.0.recordValue', $domain->recordValue())->assertJsonPath('domains.0.verifiedAt', null);

        $this->actingAs($this->owner)->postJson("{$base}/{$domain->id}/verify")->assertOk()->assertJsonPath('redirect', $page);
        $this->assertNull($domain->refresh()->verified_at);
        $this->assertNotNull($domain->last_checked_at);

        $this->dns->records['_buildpusher.shop.example.com'] = ['something-else', ' '.$domain->recordValue().' '];
        $this->actingAs($this->owner)->postJson("{$base}/{$domain->id}/verify")->assertOk()->assertJsonPath('message', __(':domain is verified.', ['domain' => 'shop.example.com']));
        $this->assertNotNull($domain->refresh()->verified_at);
        $this->assertNotNull($this->actingAs($this->owner)->getJson($base)->json('domains.0.verifiedAt'));

        $this->assertSame(
            [AuditAction::DomainAdded, AuditAction::DomainVerified],
            AuditEntry::query()->where('action', 'like', 'domain.%')->orderBy('id')->pluck('action')->all(),
        );
    }

    /**
     * Bad or duplicate hostnames and other projects' environments are refused.
     */
    public function test_bad_or_duplicate_hostnames_and_foreign_environments_are_refused(): void
    {
        $base = "/api/app/projects/{$this->project->id}/domains";
        $other = Project::factory()->create();

        $this->actingAs($this->owner)->postJson($base, ['hostname' => 'localhost'])->assertJsonValidationErrors('hostname');
        $this->actingAs($this->owner)->postJson($base, ['hostname' => 'example.com', 'environment_id' => $other->environments()->sole()->id])->assertJsonValidationErrors('environment_id');
        $this->actingAs($this->owner)->postJson($base, ['hostname' => 'example.com'])->assertOk();
        $this->actingAs($this->owner)->postJson($base, ['hostname' => 'EXAMPLE.com'])->assertJsonValidationErrors('hostname');
        $this->assertSame(1, Domain::query()->count());
    }

    /**
     * Only one project can hold a hostname verified.
     */
    public function test_only_one_project_can_hold_a_hostname_verified(): void
    {
        $theirs = Project::factory()->create();
        $claim = new Domain;
        $claim->forceFill(['project_id' => $theirs->id, 'hostname' => 'example.com', 'verification_token' => 'theirs', 'verified_at' => now()])->save();

        $base = "/api/app/projects/{$this->project->id}/domains";
        $this->actingAs($this->owner)->postJson($base, ['hostname' => 'example.com'])->assertOk();
        $ours = Domain::query()->where('project_id', $this->project->id)->sole();
        $this->dns->records['_buildpusher.example.com'] = [$ours->recordValue()];

        $this->actingAs($this->owner)->postJson("{$base}/{$ours->id}/verify")->assertJsonValidationErrors('domain');
        $this->assertNull($ours->refresh()->verified_at);
    }

    /**
     * Viewers see domains but can't change them; outsiders can't reach them.
     */
    public function test_viewers_see_domains_but_cannot_change_them_and_outsiders_cannot_reach_them(): void
    {
        $domain = new Domain;
        $domain->forceFill(['project_id' => $this->project->id, 'hostname' => 'example.com', 'verification_token' => 'x'])->save();
        $viewer = User::factory()->create();
        $this->project->account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);
        $base = "/api/app/projects/{$this->project->id}/domains";

        $this->actingAs($viewer)->getJson($base)->assertOk()->assertJsonPath('domains.0.name', 'example.com')->assertJsonPath('overview.canManage', false);
        $this->actingAs($viewer)->postJson("{$base}/{$domain->id}/verify")->assertForbidden();
        $this->actingAs($viewer)->deleteJson("{$base}/{$domain->id}")->assertForbidden();

        $elsewhere = Project::factory()->for(Account::factory()->withMember($this->owner))->create();
        $this->actingAs($this->owner)->deleteJson("/api/app/projects/{$elsewhere->id}/domains/{$domain->id}")->assertNotFound();

        $this->actingAs($this->owner)->deleteJson("{$base}/{$domain->id}")->assertOk();
        $this->assertNull($domain->fresh());
    }
}
