<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use App\Services\Deploy\Scripts\CheckoutRepositoryScript;
use App\Services\Deploy\Scripts\CloneRepositoryScript;
use App\Services\Deploy\Scripts\SymlinkScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\TestCase;

final class SubdirectoryDeployTest extends TestCase
{
    use InfrastructureHelpers;
    use RefreshDatabase;

    /**
     * A repository deployed from a subdirectory (an api/ folder in a monorepo) is cloned whole at the top of the setup
     * directory, and the app's own stages (its .env and storage, the document root) work in setup/api: not the whole
     * repository nested inside api/.
     *
     * @return void
     */
    public function test_a_subdirectory_deploy_clones_the_repository_at_the_top_and_works_in_the_subdirectory(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['deploy'])->create();
        $github = Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id]);
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id]);
        $repository = Repository::factory()->create(['project_id' => $project->id, 'website_id' => $website->id, 'provider_id' => $github->id, 'deployment_root' => 'api']);
        $build = Build::factory()->create(['repository_id' => $repository->id]);
        $root = "/var/www/{$website->deployment_slug}";

        $clone = app(CloneRepositoryScript::class)->script(1, $build);
        $this->assertStringContainsString("git clone -- 'https://{$repository->url}' '{$root}/setup'", $clone);
        $this->assertStringNotContainsString("{$root}/setup/api'", $clone);

        // The subdirectory may only exist on the branch being deployed, so git works from the top of the clone.
        $checkout = app(CheckoutRepositoryScript::class)->script(2, $build);
        $this->assertStringContainsString("git -C '{$root}/setup' ", $checkout);
        $this->assertStringNotContainsString("{$root}/setup/api", $checkout);
        $symlink = app(SymlinkScript::class)->script(5, $build);
        $this->assertStringContainsString("CURRENT_PATH='{$root}/setup/api'", $symlink);
        // Laravel's storage folders exist even when the shared storage was seeded from a release without them.
        $this->assertStringContainsString('"$SHARED_STORAGE/framework/views"', $symlink);
        $this->assertSame("{$root}/current/api", $build->deploymentPath('current'));
    }
}
