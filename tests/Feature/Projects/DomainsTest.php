<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Account;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Contracts\DnsResolver;
use App\Domain\Projects\Models\Domain;
use App\Domain\Projects\Models\Project;
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

    public function test_a_domain_is_added_then_verified_from_its_txt_record(): void
    {
        $base = "/projects/{$this->project->id}/domains";
        $production = $this->project->environments()->sole();

        $this->actingAs($this->owner)->post($base, ['hostname' => 'https://Shop.Example.com/', 'environment_id' => $production->id])->assertRedirect($base);
        $domain = Domain::query()->sole();
        $this->assertSame('shop.example.com', $domain->hostname);
        $this->assertSame($production->id, $domain->environment_id);

        $this->actingAs($this->owner)->get($base)->assertOk()->assertSee('_buildpusher.shop.example.com')->assertSee($domain->recordValue())->assertSee(__('Not verified'));

        $this->actingAs($this->owner)->post("{$base}/{$domain->id}/verify")->assertRedirect($base);
        $this->assertNull($domain->refresh()->verified_at);
        $this->assertNotNull($domain->last_checked_at);

        $this->dns->records['_buildpusher.shop.example.com'] = ['something-else', ' '.$domain->recordValue().' '];
        $this->actingAs($this->owner)->post("{$base}/{$domain->id}/verify")->assertRedirect($base);
        $this->assertNotNull($domain->refresh()->verified_at);
        $this->actingAs($this->owner)->get($base)->assertSee(__('Verified'))->assertDontSee($domain->recordValue());

        $this->assertSame(
            [AuditAction::DomainAdded, AuditAction::DomainVerified],
            AuditEntry::query()->where('action', 'like', 'domain.%')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_bad_or_duplicate_hostnames_and_foreign_environments_are_refused(): void
    {
        $base = "/projects/{$this->project->id}/domains";
        $other = Project::factory()->create();

        $this->actingAs($this->owner)->post($base, ['hostname' => 'localhost'])->assertSessionHasErrors('hostname');
        $this->actingAs($this->owner)->post($base, ['hostname' => 'example.com', 'environment_id' => $other->environments()->sole()->id])->assertSessionHasErrors('environment_id');
        $this->actingAs($this->owner)->post($base, ['hostname' => 'example.com'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($base, ['hostname' => 'EXAMPLE.com'])->assertSessionHasErrors('hostname');
        $this->assertSame(1, Domain::query()->count());
    }

    public function test_only_one_project_can_hold_a_hostname_verified(): void
    {
        $theirs = Project::factory()->create();
        $claim = new Domain;
        $claim->forceFill(['project_id' => $theirs->id, 'hostname' => 'example.com', 'verification_token' => 'theirs', 'verified_at' => now()])->save();

        $base = "/projects/{$this->project->id}/domains";
        $this->actingAs($this->owner)->post($base, ['hostname' => 'example.com'])->assertSessionHasNoErrors();
        $ours = Domain::query()->where('project_id', $this->project->id)->sole();
        $this->dns->records['_buildpusher.example.com'] = [$ours->recordValue()];

        $this->actingAs($this->owner)->post("{$base}/{$ours->id}/verify")->assertSessionHasErrors('domain');
        $this->assertNull($ours->refresh()->verified_at);
    }

    public function test_viewers_see_domains_but_cannot_change_them_and_outsiders_cannot_reach_them(): void
    {
        $domain = new Domain;
        $domain->forceFill(['project_id' => $this->project->id, 'hostname' => 'example.com', 'verification_token' => 'x'])->save();
        $viewer = User::factory()->create();
        $this->project->account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);
        $base = "/projects/{$this->project->id}/domains";

        $this->actingAs($viewer)->get($base)->assertOk()->assertSee('example.com')->assertDontSee(__('Add domain'));
        $this->actingAs($viewer)->post("{$base}/{$domain->id}/verify")->assertForbidden();
        $this->actingAs($viewer)->delete("{$base}/{$domain->id}")->assertForbidden();

        $elsewhere = Project::factory()->for(Account::factory()->withMember($this->owner))->create();
        $this->actingAs($this->owner)->delete("/projects/{$elsewhere->id}/domains/{$domain->id}")->assertNotFound();

        $this->actingAs($this->owner)->delete("{$base}/{$domain->id}")->assertRedirect($base);
        $this->assertNull($domain->fresh());
    }
}
