<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderInventoryExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtered_export_is_owner_scoped_spreadsheet_safe_and_excludes_tokens(): void
    {
        $owner = User::factory()->create();
        $matching = $this->provider($owner, '=Production Cloud', Provider::TYPE_DIGITALOCEAN, [
            'description' => " \t@HANDOFF infrastructure",
            'token' => 'owner-token-never-export',
            'connection_monitoring_enabled' => false,
        ]);
        $matching->forceFill([
            'connection_status' => Provider::CONNECTION_HEALTHY,
            'connection_checked_at' => now()->subMinute(),
        ])->save();
        $this->server($owner, $matching, '+Primary');
        $this->server($owner, $matching, '-Recovery');
        $this->provider($owner, 'Production Spare', Provider::TYPE_DIGITALOCEAN);
        $source = $this->provider($owner, 'Production GitHub', Provider::TYPE_GITHUB);
        $this->repository($owner, $source, 'Production Repository');

        $other = User::factory()->create();
        $foreign = $this->provider($other, 'Private Production Cloud', Provider::TYPE_DIGITALOCEAN, [
            'token' => 'foreign-token-never-export',
        ]);
        $this->server($other, $foreign, 'Foreign server');

        $filters = [
            'search' => 'Production',
            'type' => Provider::TYPE_DIGITALOCEAN,
            'usage' => 'in_use',
            'connection' => Provider::CONNECTION_HEALTHY,
        ];
        $response = $this->actingAs($owner)->get(route('providers.export', $filters));

        $response
            ->assertSuccessful()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString(
            'attachment; filename=lessbuild-providers-',
            (string) $response->headers->get('content-disposition'),
        );

        $content = $response->streamedContent();
        $this->assertStringNotContainsString('token-never-export', $content);
        $this->assertStringNotContainsString('Private Production Cloud', $content);
        $this->assertStringNotContainsString('Foreign server', $content);
        $this->assertStringNotContainsString('Production Spare', $content);
        $this->assertStringNotContainsString('Production GitHub', $content);

        $rows = $this->csvRows($content);
        $this->assertSame([
            'Provider ID',
            'Name',
            'Type',
            'Description',
            'Servers',
            'Server count',
            'Repositories',
            'Repository count',
            'Connection status',
            'Automatic monitoring',
            'Automatic interval minutes',
            'Failure threshold',
            'Consecutive failures',
            'Connection checked at',
            'Created at',
            'Updated at',
        ], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame((string) $matching->id, $rows[1][0]);
        $this->assertSame("'=Production Cloud", $rows[1][1]);
        $this->assertSame(Provider::TYPE_DIGITALOCEAN, $rows[1][2]);
        $this->assertSame("' \t@HANDOFF infrastructure", $rows[1][3]);
        $this->assertSame("'+Primary; -Recovery", $rows[1][4]);
        $this->assertSame('2', $rows[1][5]);
        $this->assertSame('', $rows[1][6]);
        $this->assertSame('0', $rows[1][7]);
        $this->assertSame(Provider::CONNECTION_HEALTHY, $rows[1][8]);
        $this->assertSame('paused', $rows[1][9]);
        $this->assertSame((string) Provider::DEFAULT_CONNECTION_CHECK_INTERVAL_MINUTES, $rows[1][10]);
        $this->assertSame((string) Provider::DEFAULT_CONNECTION_FAILURE_THRESHOLD, $rows[1][11]);
        $this->assertSame('0', $rows[1][12]);
        $this->assertSame($matching->connection_checked_at->toIso8601String(), $rows[1][13]);
        $this->assertSame($matching->created_at->toIso8601String(), $rows[1][14]);
        $this->assertSame($matching->updated_at->toIso8601String(), $rows[1][15]);

        $this->actingAs($owner)->get(route('providers.index', $filters))
            ->assertSuccessful()
            ->assertSee(route('providers.export', $filters));
    }

    public function test_repository_provider_export_includes_repository_metadata(): void
    {
        $owner = User::factory()->create();
        $source = $this->provider($owner, 'GitHub', Provider::TYPE_GITHUB);
        $this->repository($owner, $source, '=API');
        $this->repository($owner, $source, '+Web');

        $rows = $this->csvRows(
            $this->actingAs($owner)
                ->get(route('providers.export', ['type' => Provider::TYPE_GITHUB]))
                ->streamedContent(),
        );

        $this->assertCount(2, $rows);
        $this->assertSame("'+Web; =API", $rows[1][6]);
        $this->assertSame('2', $rows[1][7]);
        $this->assertSame(Provider::CONNECTION_UNCHECKED, $rows[1][8]);
        $this->assertSame('enabled', $rows[1][9]);
        $this->assertSame((string) Provider::DEFAULT_CONNECTION_CHECK_INTERVAL_MINUTES, $rows[1][10]);
        $this->assertSame((string) Provider::DEFAULT_CONNECTION_FAILURE_THRESHOLD, $rows[1][11]);
        $this->assertSame('0', $rows[1][12]);
        $this->assertSame('', $rows[1][13]);
    }

    public function test_export_requires_authentication(): void
    {
        $this->get(route('providers.export'))->assertRedirect(route('login'));
    }

    /** @param array<string, mixed> $attributes */
    private function provider(User $user, string $name, string $type, array $attributes = []): Provider
    {
        return $user->providers()->create([
            'name' => $name,
            'provider' => $type,
            'token' => 'provider-token',
            'description' => "{$name} description",
            ...$attributes,
        ]);
    }

    private function server(User $user, Provider $provider, string $name): Server
    {
        return $user->servers()->create([
            'provider_id' => $provider->id,
            'name' => $name,
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
    }

    private function repository(User $user, Provider $provider, string $name): void
    {
        $server = $user->servers()->create([
            'name' => "{$name} Server",
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => "{$name} Website",
            'url' => str($name)->slug().'.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'provisioning_status' => Website::STATUS_ACTIVE,
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
