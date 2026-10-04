<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Enums\AccountRole;
use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class InventoryExportTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check each inventory downloads as CSV with the account's records only, formulas neutralised and no secrets, and
     * providers only for people who manage the account.
     *
     * @return void
     */
    /**
     * The account's inventories download as CSV, safe to open in a spreadsheet, and limited to what the person sees.
     */
    public function test_the_account_inventories_download_as_csv(): void
    {
        $project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $provider = Provider::factory()->create(['account_id' => $project->account_id, 'name' => 'Main cloud', 'token' => 'do-secret-token']);
        $server = Server::factory()->create(['provider_id' => $provider->id, 'name' => 'web-1', 'recipe_snapshot' => [['name' => 'Install ffmpeg', 'description' => null, 'script' => 'apt install ffmpeg']]]);
        $website = Website::factory()->create(['server_id' => $server->id, 'name' => '=HYPERLINK("x")', 'url' => 'shop.example.com']);
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id, 'name' => 'GitHub']);
        $repository = Repository::factory()->create(['website_id' => $website->id, 'project_id' => $project->id, 'provider_id' => $github->id, 'name' => 'shop']);
        Build::factory()->create(['repository_id' => $repository->id, 'status' => Build::STATUS_SUCCEEDED, 'revision' => str_repeat('a', 40)]);
        (new Recipe)->forceFill(['account_id' => $project->account_id, 'name' => 'Install ffmpeg', 'category' => 'utilities', 'script' => 'apt install ffmpeg'])->save();
        Server::factory()->create(['name' => 'someone-elses']);

        $servers = $this->actingAs($owner)->get('/api/app/account/inventory/servers.csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString('"Server ID",Name,"Cloud identifier"', $servers);
        $this->assertStringContainsString('web-1', $servers);
        $this->assertStringNotContainsString('someone-elses', $servers);
        $this->assertStringContainsString("'=HYPERLINK", $this->actingAs($owner)->get('/api/app/account/inventory/websites.csv')->assertOk()->streamedContent());
        $providers = $this->actingAs($owner)->get('/api/app/account/inventory/providers.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Main cloud', $providers);
        $this->assertStringNotContainsString('do-secret-token', $providers);
        $this->assertStringContainsString('"Install ffmpeg",,utilities,no,0,no,web-1,1', $this->actingAs($owner)->get('/api/app/account/inventory/recipes.csv')->assertOk()->streamedContent());
        $this->assertStringContainsString(str_repeat('a', 40), $this->actingAs($owner)->get('/api/app/account/inventory/repositories.csv')->assertOk()->streamedContent());
        $this->actingAs($owner)->get('/api/app/account/inventory/passwords.csv')->assertNotFound();

        $viewer = User::factory()->create();
        $this->addMember($project, $viewer, AccountRole::Viewer);
        $viewer->forceFill(['current_account_id' => $project->account_id])->save();
        $this->assertStringNotContainsString('Main cloud', $this->actingAs($viewer)->get('/api/app/account/inventory/providers.csv')->assertOk()->streamedContent());
    }
}
