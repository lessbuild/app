<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteInventoryExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtered_inventory_export_is_owner_scoped_spreadsheet_safe_and_secret_free(): void
    {
        $owner = User::factory()->create();
        $server = $this->server($owner, '-Production Edge');
        $matching = $this->website($owner, $server, '=Customer Portal', [
            'url' => '+customer.example.com',
            'description' => " \t@HANDOFF website",
            'provisioning_status' => Website::STATUS_FAILED,
            'health_check_enabled' => true,
            'health_monitoring_enabled' => false,
            'health_check_interval_minutes' => 15,
            'health_failure_threshold' => 5,
            'health_status' => Website::HEALTH_UNHEALTHY,
            'health_failure_count' => 4,
            'health_last_checked_at' => '2026-08-30 12:00:00',
            'health_last_error' => 'health-error-never-export',
            'release_retention' => 7,
            'environment' => 'ENVIRONMENT_SECRET=never-export',
            'database_password' => 'database-password-never-export',
            'provisioning_token' => 'provisioning-token-never-export',
            'provisioned_at' => '2026-08-29 12:00:00',
        ]);
        $this->repository($owner, $matching, 'First Repository');
        $this->repository($owner, $matching, 'Second Repository');

        $healthy = $this->website($owner, $server, 'Customer Healthy', [
            'provisioning_status' => Website::STATUS_FAILED,
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_HEALTHY,
        ]);
        $this->repository($owner, $healthy, 'Healthy Repository');

        $other = User::factory()->create();
        $otherServer = $this->server($other, 'Private Edge');
        $foreign = $this->website($other, $otherServer, 'Customer Private', [
            'provisioning_status' => Website::STATUS_FAILED,
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_UNHEALTHY,
        ]);
        $this->repository($other, $foreign, 'Private Repository');

        $filters = [
            'search' => 'customer',
            'status' => Website::STATUS_FAILED,
            'health' => Website::HEALTH_UNHEALTHY,
            'attention' => 1,
        ];
        $response = $this->actingAs($owner)->get(route('websites.export', $filters));

        $response
            ->assertSuccessful()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString(
            'attachment; filename=lessbuild-websites-',
            (string) $response->headers->get('content-disposition'),
        );

        $content = $response->streamedContent();
        $this->assertStringNotContainsString('ENVIRONMENT_SECRET', $content);
        $this->assertStringNotContainsString('database-password-never-export', $content);
        $this->assertStringNotContainsString('provisioning-token-never-export', $content);
        $this->assertStringNotContainsString('health-error-never-export', $content);

        $rows = $this->csvRows($content);
        $this->assertSame([
            'Website ID',
            'Name',
            'Domain',
            'Description',
            'Server',
            'Provisioning status',
            'Health check',
            'Automatic monitoring',
            'Automatic check interval minutes',
            'Outage confirmation failures',
            'Health status',
            'Health failure count',
            'Last health check at',
            'Release retention',
            'Repository count',
            'Provisioned at',
            'Created at',
        ], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame((string) $matching->id, $rows[1][0]);
        $this->assertSame("'=Customer Portal", $rows[1][1]);
        $this->assertSame("'+customer.example.com", $rows[1][2]);
        $this->assertSame("' \t@HANDOFF website", $rows[1][3]);
        $this->assertSame("'-Production Edge", $rows[1][4]);
        $this->assertSame(Website::STATUS_FAILED, $rows[1][5]);
        $this->assertSame('enabled', $rows[1][6]);
        $this->assertSame('paused', $rows[1][7]);
        $this->assertSame('15', $rows[1][8]);
        $this->assertSame('5', $rows[1][9]);
        $this->assertSame(Website::HEALTH_UNHEALTHY, $rows[1][10]);
        $this->assertSame('4', $rows[1][11]);
        $this->assertSame($matching->health_last_checked_at->toIso8601String(), $rows[1][12]);
        $this->assertSame('7', $rows[1][13]);
        $this->assertSame('2', $rows[1][14]);
        $this->assertSame($matching->provisioned_at->toIso8601String(), $rows[1][15]);

        $this->actingAs($owner)->get(route('websites.index', $filters))
            ->assertSuccessful()
            ->assertSee(route('websites.export', $filters));
    }

    public function test_disabled_health_checks_export_the_effective_disabled_state(): void
    {
        $owner = User::factory()->create();
        $server = $this->server($owner, 'Production');
        $this->website($owner, $server, 'Disabled Monitor', [
            'health_check_enabled' => false,
            'health_status' => Website::HEALTH_UNHEALTHY,
            'health_failure_count' => 3,
        ]);

        $response = $this->actingAs($owner)->get(route('websites.export', [
            'health' => 'disabled',
        ]));

        $rows = $this->csvRows($response->streamedContent());
        $this->assertCount(2, $rows);
        $this->assertSame('disabled', $rows[1][6]);
        $this->assertSame('disabled', $rows[1][7]);
        $this->assertSame('5', $rows[1][8]);
        $this->assertSame('3', $rows[1][9]);
        $this->assertSame('disabled', $rows[1][10]);
    }

    public function test_export_requires_authentication(): void
    {
        $this->get(route('websites.export'))->assertRedirect(route('login'));
    }

    private function server(User $user, string $name): Server
    {
        return $user->servers()->create([
            'name' => $name,
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function website(
        User $user,
        Server $server,
        string $name,
        array $attributes = [],
    ): Website {
        return $user->websites()->create([
            'server_id' => $server->id,
            'name' => $name,
            'url' => str($name)->slug().'.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'provisioning_status' => Website::STATUS_ACTIVE,
            ...$attributes,
        ]);
    }

    private function repository(User $user, Website $website, string $name): void
    {
        $provider = $user->providers()->firstOrCreate([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
        ], [
            'token' => 'source-token',
            'description' => 'Source provider',
        ]);

        $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => $name,
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Repository',
        ]);
    }

    /** @return list<list<string|null>> */
    private function csvRows(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://temp', 'w+b');
        $this->assertNotFalse($stream);
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
