<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Enums\ProviderType;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Notifications\ProviderConnectionChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ProvidersTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->ownerOf(Project::factory()->create());
        $this->withoutMiddleware(\Illuminate\Auth\Middleware\RequirePassword::class);
    }

    /**
     * Owners connect, change and remove providers; tokens are encrypted and never returned.
     */
    public function test_owners_connect_change_and_remove_providers(): void
    {
        $this->actingAs($this->owner)->getJson('/api/app/account/providers')->assertOk()->assertJsonPath('providers', []);
        $this->actingAs($this->owner)->postJson('/api/app/account/providers', ['name' => 'Production cloud', 'type' => 'hetzner', 'token' => 'secret-token-1'])->assertOk();

        $provider = Provider::query()->sole();
        $this->assertSame(ProviderType::Hetzner, $provider->type);
        $this->assertSame('secret-token-1', $provider->token);
        $this->assertStringNotContainsString('secret-token-1', (string) $provider->getRawOriginal('token'));
        $this->assertSame($this->owner->id, $provider->created_by);
        $this->actingAs($this->owner)->getJson("/api/app/account/providers/{$provider->id}")->assertOk()->assertJsonPath('provider.name', 'Production cloud')->assertDontSee('secret-token-1');

        $provider->forceFill(['connection_status' => 'healthy', 'connection_checked_at' => now()])->save();
        $this->actingAs($this->owner)->putJson("/api/app/account/providers/{$provider->id}", ['name' => 'Renamed', 'type' => 'hetzner', 'token' => ''])->assertOk();
        $this->assertSame('secret-token-1', $this->reload($provider)->token);
        $this->assertSame('healthy', $this->reload($provider)->connection_status);
        $this->actingAs($this->owner)->putJson("/api/app/account/providers/{$provider->id}", ['name' => 'Renamed', 'type' => 'hetzner', 'token' => 'secret-token-2'])->assertOk();
        $this->assertSame('unchecked', $this->reload($provider)->connection_status);

        Server::factory()->create(['provider_id' => $provider->id]);
        $this->actingAs($this->owner)->putJson("/api/app/account/providers/{$provider->id}", ['name' => 'Renamed', 'type' => 'vultr'])->assertJsonValidationErrors('type');
        $this->actingAs($this->owner)->deleteJson("/api/app/account/providers/{$provider->id}")->assertJsonValidationErrors('provider');
        Server::query()->delete();
        $this->actingAs($this->owner)->deleteJson("/api/app/account/providers/{$provider->id}")->assertOk()->assertJsonPath('redirect', '/account/providers');
        $this->assertSoftDeleted($provider);
        $this->assertSame(
            [AuditAction::ProviderCreated, AuditAction::ProviderUpdated, AuditAction::ProviderUpdated, AuditAction::ProviderDeleted],
            AuditEntry::query()->where('account_id', $provider->account_id)->orderBy('id')->pluck('action')->all(),
        );
    }

    /**
     * Members can't see or manage providers; other accounts' providers aren't found.
     */
    public function test_members_cant_see_or_manage_providers(): void
    {
        $member = User::factory()->create();
        $this->addMember($this->owner->currentAccount ?? $this->fail(), $member, AccountRole::Member);
        $member->forceFill(['current_account_id' => $this->owner->current_account_id])->save();
        $provider = Provider::factory()->create(['account_id' => $this->owner->current_account_id]);

        $this->actingAs($member)->getJson('/api/app/account/providers')->assertForbidden();
        $this->actingAs($member)->postJson('/api/app/account/providers', ['name' => 'Nope', 'type' => 'vultr', 'token' => 'x'])->assertForbidden();
        $this->actingAs($member)->postJson("/api/app/account/providers/{$provider->id}/check")->assertForbidden();
        $this->actingAs($this->owner)->getJson('/api/app/account/providers/'.Provider::factory()->create()->id)->assertNotFound();
    }

    /**
     * Checks record health history and tell the creator when the connection changes.
     */
    public function test_checks_record_health_history_and_tell_the_creator_about_changes(): void
    {
        Notification::fake();
        $provider = Provider::factory()->create(['account_id' => $this->owner->current_account_id, 'created_by' => $this->owner->id, 'connection_failure_threshold' => 2]);
        Http::fake(['https://api.digitalocean.com/*' => Http::sequence()->push([], 401)->push([], 401)->push(['droplets' => []], 200)]);

        $this->actingAs($this->owner)->postJson("/api/app/account/providers/{$provider->id}/check")->assertOk()->assertJsonPath('successful', false);
        $this->assertSame('unchecked', $this->reload($provider)->connection_status);
        Notification::assertNothingSent();
        $this->actingAs($this->owner)->postJson("/api/app/account/providers/{$provider->id}/check")->assertJsonPath('message', 'Connection failed. DigitalOcean returned HTTP 401. Check the credential and its permissions.');
        $this->assertSame('failed', $this->reload($provider)->connection_status);
        Notification::assertSentToTimes($this->owner, ProviderConnectionChanged::class, 1);
        $this->actingAs($this->owner)->postJson("/api/app/account/providers/{$provider->id}/check")->assertJsonPath('successful', true);
        $this->assertSame('healthy', $this->reload($provider)->connection_status);
        Notification::assertSentToTimes($this->owner, ProviderConnectionChanged::class, 2);
        $this->assertSame([false, false, true], $provider->connectionChecks()->orderBy('id')->pluck('successful')->all());
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer '.$provider->token));
    }

    /**
     * The scheduled check only runs for providers that are monitored and due.
     */
    public function test_the_scheduled_check_only_runs_due_monitored_providers(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $due = Provider::factory()->create(['connection_checked_at' => now()->subHours(25)]);
        $never = Provider::factory()->create();
        Provider::factory()->create(['connection_checked_at' => now()->subHours(2)]);
        Provider::factory()->create(['connection_monitoring_enabled' => false]);
        $hourly = Provider::factory()->create(['connection_check_interval_minutes' => 60, 'connection_checked_at' => now()->subMinutes(61)]);

        $this->command('providers:check')->expectsOutput('Checked 3 providers; 0 failed; 0 discarded.')->assertSuccessful();

        foreach ([$due, $never, $hourly] as $provider) {
            $this->assertSame('healthy', $this->reload($provider)->connection_status);
        }
        $this->assertSame(3, \App\Models\ProviderConnectionCheck::query()->where('source', 'automatic')->count());
    }
}
