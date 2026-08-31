<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepositoryInventoryExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtered_inventory_export_is_owner_scoped_and_spreadsheet_safe(): void
    {
        $owner = User::factory()->create();
        $provider = $this->provider($owner, '=Owner GitHub');
        $website = $this->website($owner, '+Customer Portal');
        $matching = $this->repository($owner, $provider, $website, '=Customer API', [
            'branch' => '-release',
            'description' => " \t@HANDOFF repository",
            'build_commands' => 'build-command-never-export',
            'post_deployment_commands' => 'post-command-never-export',
            'webhook_enabled' => true,
            'webhook_secret' => 'webhook-secret-never-export',
        ]);
        $build = $matching->builds()->create([
            'status' => Build::STATUS_FAILED,
            'revision' => '-revision',
        ]);

        $recovered = $this->repository($owner, $provider, $website, 'Customer Recovered');
        $recovered->builds()->create(['status' => Build::STATUS_FAILED]);
        $recovered->builds()->create(['status' => Build::STATUS_SUCCEEDED]);

        $otherOwner = User::factory()->create();
        $otherProvider = $this->provider($otherOwner, 'Private GitHub');
        $otherWebsite = $this->website($otherOwner, 'Private Customer Portal');
        $foreign = $this->repository($otherOwner, $otherProvider, $otherWebsite, 'Customer Private');
        $foreign->builds()->create(['status' => Build::STATUS_FAILED]);

        $filters = [
            'search' => 'Customer',
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'status' => Build::STATUS_FAILED,
        ];
        $response = $this->actingAs($owner)->get(route('repositories.export', $filters));

        $response
            ->assertSuccessful()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString(
            'attachment; filename=lessbuild-repositories-',
            (string) $response->headers->get('content-disposition'),
        );

        $content = $response->streamedContent();
        $this->assertStringNotContainsString('provider-token-never-export', $content);
        $this->assertStringNotContainsString('build-command-never-export', $content);
        $this->assertStringNotContainsString('post-command-never-export', $content);
        $this->assertStringNotContainsString('webhook-secret-never-export', $content);

        $rows = $this->csvRows($content);
        $this->assertSame([
            'Repository ID',
            'Name',
            'URL',
            'Branch',
            'Description',
            'Provider',
            'Provider type',
            'Website',
            'Website domain',
            'Server',
            'Latest deployment status',
            'Latest revision',
            'Latest deployment at',
            'Webhook enabled',
            'Created at',
        ], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame((string) $matching->id, $rows[1][0]);
        $this->assertSame("'=Customer API", $rows[1][1]);
        $this->assertSame("'-release", $rows[1][3]);
        $this->assertSame("' \t@HANDOFF repository", $rows[1][4]);
        $this->assertSame("'=Owner GitHub", $rows[1][5]);
        $this->assertSame("'+Customer Portal", $rows[1][7]);
        $this->assertSame("'+Customer Portal Server", $rows[1][9]);
        $this->assertSame(Build::STATUS_FAILED, $rows[1][10]);
        $this->assertSame("'-revision", $rows[1][11]);
        $this->assertSame($build->created_at->toIso8601String(), $rows[1][12]);
        $this->assertSame('yes', $rows[1][13]);

        $this->actingAs($owner)->get(route('repositories.index', $filters))
            ->assertSuccessful()
            ->assertSee(route('repositories.export', $filters));
    }

    public function test_foreign_provider_filter_exports_only_the_header(): void
    {
        $owner = User::factory()->create();
        $ownProvider = $this->provider($owner, 'Owner GitHub');
        $ownWebsite = $this->website($owner, 'Owner Website');
        $this->repository($owner, $ownProvider, $ownWebsite, 'Owner Repository');

        $other = User::factory()->create();
        $foreignProvider = $this->provider($other, 'Foreign GitHub');
        $foreignWebsite = $this->website($other, 'Foreign Website');
        $this->repository($other, $foreignProvider, $foreignWebsite, 'Foreign Repository');

        $response = $this->actingAs($owner)->get(route('repositories.export', [
            'provider_id' => $foreignProvider->id,
        ]));

        $response->assertSuccessful();
        $this->assertCount(1, $this->csvRows($response->streamedContent()));
    }

    public function test_export_requires_authentication(): void
    {
        $this->get(route('repositories.export'))->assertRedirect(route('login'));
    }

    private function provider(User $user, string $name): Provider
    {
        return $user->providers()->create([
            'name' => $name,
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-token-never-export',
            'description' => 'Source provider',
        ]);
    }

    private function website(User $user, string $name): Website
    {
        $server = $user->servers()->create([
            'name' => "{$name} Server",
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);

        return $user->websites()->create([
            'server_id' => $server->id,
            'name' => $name,
            'url' => str($name)->slug().'.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function repository(
        User $user,
        Provider $provider,
        Website $website,
        string $name,
        array $attributes = [],
    ): Repository {
        return $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => $name,
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Repository',
            ...$attributes,
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
