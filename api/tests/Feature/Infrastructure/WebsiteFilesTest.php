<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class WebsiteFilesTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check the file browser: folders listed folders first, a file's end shown, logs searched, paths kept inside the
     * website's folder (checked here and on the server), and only people who may run commands can use it.
     *
     * @return void
     */
    public function test_the_website_folder_can_be_browsed_read_and_searched(): void
    {
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $project = Project::factory()->withServices(['infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $server = Server::factory()->create(['account_id' => $project->account_id, 'provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => 'shop']);
        $base = "/api/app/projects/{$project->id}/infrastructure/websites/{$website->id}";

        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('canBrowseFiles', true);

        $this->shell->reply("f\t120\t1790000000\tlaravel.log\nd\t4096\t1790000000\tlogs\nl\t20\t1790000000\tcurrent\n");
        $this->actingAs($owner)->getJson("{$base}/files?path=shared/storage")->assertOk()
            ->assertJsonPath('folder.entries', fn (array $entries): bool => collect($entries)->contains(fn (array $entry): bool => $entry['name'] === 'laravel.log' && $entry['size'] === 120))
            ->assertJsonPath('folder.entries', fn (array $entries): bool => collect($entries)->pluck('name')->sort()->values()->all() === ['current', 'laravel.log', 'logs']);
        $command = (string) (collect($this->shell->ran)->last()['command'] ?? '');
        $this->assertStringContainsString("ROOT='/var/www/shop'", $command);
        $this->assertStringContainsString("realpath -e -- '/var/www/shop/shared/storage'", $command);
        $this->assertStringContainsString('case "$P" in "$ROOT"|"$ROOT"/*) ;; *) echo', $command);

        $this->shell->reply("[2026-09-30] production.ERROR: boom\n");
        $this->actingAs($owner)->getJson("{$base}/files?file=shared/storage/logs/laravel.log")->assertOk()->assertSee('production.ERROR: boom');
        $this->assertStringContainsString('tail -n 500', (string) (collect($this->shell->ran)->last()['command'] ?? ''));

        $this->shell->reply('', 3, 'That leads outside the website’s folder.');
        $this->actingAs($owner)->getJson("{$base}/files?path=current")->assertOk()->assertJsonPath('folder.error', 'That leads outside the website’s folder.');
        $this->actingAs($owner)->getJson("{$base}/files?path=../../etc")->assertJsonValidationErrors('path');
        // A leading slash is still inside the website's folder.
        $this->actingAs($owner)->getJson("{$base}/files?file=/etc/shadow")->assertOk();
        $this->assertStringContainsString("realpath -e -- '/var/www/shop/etc/shadow'", (string) (collect($this->shell->ran)->last()['command'] ?? ''));

        $this->shell->reply("/var/www/shop/shared/storage/logs/laravel.log:42:[2026-09-30] SQLSTATE[HY000] gone away\n");
        $this->actingAs($owner)->getJson("{$base}/files?q=SQLSTATE")->assertOk()->assertJsonCount(1, 'search.matches')->assertJsonPath('search.matches.0.line', 42)->assertSee('gone away');
        $this->assertStringContainsString("-F -e 'SQLSTATE'", (string) (collect($this->shell->ran)->last()['command'] ?? ''));

        $viewer = User::factory()->create();
        $this->addMember($project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->getJson("{$base}/files")->assertForbidden();
        $this->actingAs($viewer)->getJson($base)->assertOk()->assertJsonPath('canBrowseFiles', false);
    }
}
