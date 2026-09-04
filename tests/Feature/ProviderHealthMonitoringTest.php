<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\ProviderConnectionCheck;
use App\Models\User;
use App\Notifications\FailureNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderHealthMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_checks_a_bounded_batch_of_unchecked_and_stale_providers(): void
    {
        config([
            'lessbuild.provider_health_batch_size' => 2,
            'lessbuild.provider_health_interval_minutes' => 60,
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['login' => 'owner'], 200)
            ->push(['message' => 'expired'], 401);
        $owner = User::factory()->create();
        $unchecked = $this->provider($owner, 'Unchecked');
        $stale = $this->provider($owner, 'Stale', Provider::CONNECTION_HEALTHY, now()->subHours(2));
        $recentCheckedAt = now()->subMinutes(10);
        $recent = $this->provider($owner, 'Recent', Provider::CONNECTION_FAILED, $recentCheckedAt);

        $this->assertSame(0, Artisan::call('lessbuild:providers:health'));
        $this->assertStringContainsString(
            'Checked 2 provider(s); 1 failed; 0 stale result(s) discarded.',
            Artisan::output(),
        );
        $this->assertSame(Provider::CONNECTION_HEALTHY, $unchecked->fresh()->connection_status);
        $this->assertSame(Provider::CONNECTION_FAILED, $stale->fresh()->connection_status);
        $this->assertSame(Provider::CONNECTION_FAILED, $recent->fresh()->connection_status);
        $this->assertSame($recentCheckedAt->timestamp, $recent->fresh()->connection_checked_at->timestamp);
        $this->assertSame(1, $unchecked->connectionChecks()->count());
        $this->assertSame(1, $stale->connectionChecks()->count());
        $this->assertSame(0, $recent->connectionChecks()->count());
        $this->assertTrue($unchecked->connectionChecks()->sole()->successful);
        $this->assertSame(ProviderConnectionCheck::SOURCE_AUTOMATIC, $unchecked->connectionChecks()->sole()->source);
        $this->assertFalse($stale->connectionChecks()->sole()->successful);
        Http::assertSentCount(2);

        Artisan::call('lessbuild:providers:health');
        $this->assertStringContainsString('Checked 0 provider(s)', Artisan::output());
        Http::assertSentCount(2);
    }

    public function test_failure_and_recovery_transitions_create_linked_alerts_without_duplicates(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['message' => 'expired'], 401)
            ->push(['message' => 'still expired'], 401)
            ->push(['login' => 'owner'], 200);
        $owner = User::factory()->create();
        $provider = $this->provider($owner, 'Production GitHub');
        $arguments = ['--provider' => [$provider->id]];

        Artisan::call('lessbuild:providers:health', $arguments);
        $provider->refresh();
        $this->assertSame(Provider::CONNECTION_FAILED, $provider->connection_status);
        $failure = $owner->unreadNotifications()->sole();
        $this->assertSame('provider', $failure->data['category']);
        $this->assertSame('failed', $failure->data['status']);
        $this->assertSame(route('providers.show', $provider), FailureNotification::destination($failure->data));
        $this->assertDatabaseHas('events', [
            'parentable_type' => Provider::class,
            'parentable_id' => $provider->id,
            'category' => 'provider',
            'event' => 'Provider "Production GitHub" connection failed.',
        ]);

        Artisan::call('lessbuild:providers:health', $arguments);
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(1, $provider->events()->count());

        Artisan::call('lessbuild:providers:health', $arguments);
        $provider->refresh();
        $this->assertSame(Provider::CONNECTION_HEALTHY, $provider->connection_status);
        $this->assertSame(2, $owner->notifications()->count());
        $this->assertSame(2, $provider->events()->count());
        $this->assertNotNull($failure->fresh()->read_at);
        $this->assertSame(1, $owner->unreadNotifications()->count());
        $recovery = $owner->notifications()
            ->where('data->status', 'healthy')
            ->sole();
        $this->assertSame('Provider "Production GitHub" connection recovered', $recovery->data['title']);
        $this->assertNull($recovery->read_at);
        $this->assertSame(route('providers.show', $provider), FailureNotification::destination($recovery->data));
        $checks = $provider->connectionChecks()->oldest('id')->get();
        $this->assertCount(3, $checks);
        $this->assertEqualsCanonicalizing([false, false, true], $checks->pluck('successful')->all());
        $this->assertTrue($checks->every(fn (ProviderConnectionCheck $check): bool => $check->source === ProviderConnectionCheck::SOURCE_AUTOMATIC));

        $this->actingAs($owner)->get(route('notifications.index', ['category' => 'provider']))
            ->assertSuccessful()
            ->assertSee('Provider &quot;Production GitHub&quot; connection failed', false)
            ->assertSee('Provider &quot;Production GitHub&quot; connection recovered', false)
            ->assertSee('border-green-300', false);
        $this->actingAs($owner)->get(route('activity.index', ['category' => 'provider']))
            ->assertSuccessful()
            ->assertSee('Provider &quot;Production GitHub&quot; connection failed.', false)
            ->assertSee('Provider &quot;Production GitHub&quot; connection recovered.', false)
            ->assertSee(route('providers.show', $provider));
        $this->actingAs($owner)->post(route('notifications.read', $recovery))
            ->assertRedirect(route('providers.show', $provider));
    }

    public function test_explicit_ids_are_sanitized_and_missing_providers_are_ignored(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/user' => Http::response(['login' => 'owner'])]);
        $owner = User::factory()->create();
        $provider = $this->provider($owner, 'Explicit');

        Artisan::call('lessbuild:providers:health', [
            '--provider' => [$provider->id, (string) $provider->id, 'invalid', '-1', '999999'],
        ]);

        $this->assertStringContainsString('Checked 1 provider(s)', Artisan::output());
        Http::assertSentCount(1);
    }

    public function test_accepted_check_retains_only_the_newest_hundred_results(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/user' => Http::response(['login' => 'owner'])]);
        $owner = User::factory()->create();
        $provider = $this->provider($owner, 'Bounded history');
        foreach (range(1, ProviderConnectionCheck::MAX_PER_PROVIDER) as $position) {
            $provider->connectionChecks()->create([
                'successful' => false,
                'source' => ProviderConnectionCheck::SOURCE_AUTOMATIC,
                'provider_type' => Provider::TYPE_GITHUB,
                'http_status' => 401,
                'duration_ms' => 100,
                'endpoint' => 'https://api.github.com/user',
                'error' => 'Historical failure',
                'checked_at' => now()->subMinutes(101 - $position),
            ]);
        }
        $oldestId = $provider->connectionChecks()->oldest('checked_at')->value('id');

        Artisan::call('lessbuild:providers:health', ['--provider' => [$provider->id]]);

        $this->assertSame(ProviderConnectionCheck::MAX_PER_PROVIDER, $provider->connectionChecks()->count());
        $this->assertDatabaseMissing('provider_connection_checks', ['id' => $oldestId]);
        $this->assertDatabaseHas('provider_connection_checks', [
            'provider_id' => $provider->id,
            'successful' => true,
            'source' => ProviderConnectionCheck::SOURCE_AUTOMATIC,
            'provider_type' => Provider::TYPE_GITHUB,
            'http_status' => 200,
            'endpoint' => 'https://api.github.com/user',
            'error' => null,
        ]);
    }

    public function test_daemon_installer_runs_provider_checks_in_the_existing_health_timer(): void
    {
        $installer = file_get_contents(base_path('scripts/install-daemon.sh'));
        $this->assertIsString($installer);
        $this->assertStringContainsString('artisan lessbuild:websites:health', $installer);
        $this->assertStringContainsString('artisan lessbuild:providers:health', $installer);
        $this->assertStringContainsString('OnCalendar=*-*-* *:0/5:00', $installer);
    }

    private function provider(
        User $user,
        string $name,
        ?string $status = null,
        mixed $checkedAt = null,
    ): Provider {
        return $user->providers()->create([
            'name' => $name,
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-secret',
            'description' => 'Provider health monitoring',
            'connection_status' => $status,
            'connection_checked_at' => $checkedAt,
        ]);
    }
}
