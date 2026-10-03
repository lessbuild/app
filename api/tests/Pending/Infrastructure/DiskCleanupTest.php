<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerDiskScan;
use App\Models\Website;
use App\Services\Infrastructure\DiskCleanup;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class DiskCleanupTest extends TestCase
{
    use InfrastructureHelpers, MonitoringHelpers {
        MonitoringHelpers::ownerOf insteadof InfrastructureHelpers;
    }
    use RefreshDatabase;

    /**
     * Check the disk is measured by category, a category is cleared and measured again, releases beyond each
     * website's kept ones are chosen (never the live one), and every script is valid bash.
     *
     * @return void
     */
    public function test_the_disk_is_measured_and_cleared_by_category(): void
    {
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $project = Project::factory()->withServices(['infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $server = Server::factory()->create(['account_id' => $project->account_id, 'provisioning_status' => Server::STATUS_ACTIVE, 'provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => 'shop', 'release_retention' => 3]);
        $url = "/projects/{$project->id}/infrastructure/servers/{$server->id}/disk";

        $this->shell->reply("releases 3221225472 4\nlogs 104857600 12\ncaches 524288000 3\ndocker 0 0\ntmp 1048576 2\ndisk 26843545600 2147483648\n");
        $this->actingAs($owner)->post($url)->assertRedirect();
        $scan = ServerDiskScan::query()->sole();
        $this->assertSame(['ready', 3221225472, 4], [$scan->status, $scan->findings['releases']['bytes'] ?? null, $scan->findings['releases']['items'] ?? null]);
        $this->actingAs($owner)->get("/projects/{$project->id}/infrastructure/servers/{$server->id}?tab=diagnostics")->assertOk()
            ->assertSee('Disk clean-up')->assertSee('Old releases')->assertSee('3 GB')->assertSee('2 GB free of 25 GB');

        $this->shell->reply('');
        $this->shell->reply("releases 0 0\nlogs 104857600 12\ncaches 524288000 3\ndocker 0 0\ntmp 1048576 2\ndisk 26843545600 5368709120\n");
        $this->actingAs($owner)->post($url, ['clean' => 'releases'])->assertRedirect();
        $commands = array_column($this->shell->ran, 'command');
        $this->assertStringContainsString("find '/var/www/shop'/releases", $commands[1]);
        $this->assertStringContainsString('tail -n +4', $commands[1], 'Three releases are kept.');
        $this->assertStringContainsString('rm -rf -- "$path"', $commands[1]);
        $this->assertSame([0, 'releases'], [$scan->refresh()->findings['releases']['bytes'] ?? null, $scan->last_cleaned]);
        $this->actingAs($owner)->post($url, ['clean' => 'everything'])->assertSessionHasErrors('category');

        $cleanup = app(DiskCleanup::class);
        foreach ([$cleanup->scan($server), ...array_map(fn (string $category): string => $cleanup->clean($server, $category), array_keys(DiskCleanup::CATEGORIES))] as $script) {
            exec('bash -n <<\'SCRIPT\''."\n".$script."\nSCRIPT\n".' 2>&1', $output, $code);
            $this->assertSame(0, $code, implode("\n", $output));
        }
    }
}
