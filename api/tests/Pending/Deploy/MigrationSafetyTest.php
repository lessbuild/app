<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Enums\AccountRole;
use App\Enums\ProviderType;
use App\Models\Build;
use App\Models\MigrationApproval;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\Deploy\Scripts\ArtisanCommandsScript;
use App\Services\Infrastructure\ProvisioningCallbackUrl;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class MigrationSafetyTest extends TestCase
{
    use InfrastructureHelpers, MonitoringHelpers {
        MonitoringHelpers::ownerOf insteadof InfrastructureHelpers;
    }
    use RefreshDatabase;

    /**
     * Check the migration safety setting adds a pretend-and-grep check before migrating, a deploy that stops reports
     * its destructive statements, someone other than the requester approves them and the same revision deploys again
     * without the check, and the check stops (in bash) on a dropped column but not on a new table.
     *
     * @return void
     */
    public function test_destructive_migrations_wait_for_approval(): void
    {
        $this->fakeInfrastructure();
        $this->withoutMiddleware(RequirePassword::class);
        $project = Project::factory()->withServices(['deploy', 'infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $this->onTier($project, 'deploy', 'pro');
        $developer = User::factory()->create();
        $this->addMember($project, $developer, AccountRole::Admin);
        $environment = $project->environments()->where('slug', 'production')->firstOrFail();
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id]);
        $website = Website::factory()->create(['server_id' => $server->id, 'deployment_slug' => 'shop', 'url' => 'shop.example.com']);
        $repository = Repository::factory()->create(['project_id' => $project->id, 'website_id' => $website->id, 'environment_id' => $environment->id,
            'provider_id' => Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id])->id]);

        $this->actingAs($owner)->put("/projects/{$project->id}/deploy/environments/{$environment->id}/settings", ['deployment_strategy' => 'blue_green', 'rolling_pause_seconds' => 2, 'runtime_type' => 'php', 'minimum_replicas' => 1, 'maximum_replicas' => 1, 'desired_replicas' => 1, 'migration_safety' => '1'])->assertRedirect();
        $this->assertTrue($environment->refresh()->migration_safety);

        $build = Build::factory()->create(['repository_id' => $repository->id, 'environment_id' => $environment->id, 'requested_by' => $developer->id, 'revision' => str_repeat('a', 40), 'status' => 'failed', 'environment_payload' => ['migration_safety' => true]]);
        $script = app(ArtisanCommandsScript::class)->script(7, $build->load('repository.website'));
        $this->assertStringContainsString('php artisan migrate --pretend --force', $script);
        $this->assertLessThan(strpos($script, 'php artisan migrate --force'), strpos($script, '--pretend'), 'The check runs before migrating.');

        $this->post(ProvisioningCallbackUrl::buildMigrations($build), ['statements' => 'alter table `orders` drop `legacy_total`'])->assertNoContent();
        $this->assertSame('alter table `orders` drop `legacy_total`', $build->refresh()->destructive_migrations);
        $page = "/projects/{$project->id}/deploy/builds/{$build->id}";
        $this->actingAs($owner)->get($page)->assertOk()->assertSee('Destructive migrations')->assertSee('legacy_total')->assertSee(__('Approve these migrations and deploy'));
        $this->actingAs($developer)->post("{$page}/approve-migrations")->assertForbidden();
        $this->actingAs($owner)->post("{$page}/approve-migrations")->assertRedirect();
        $this->assertSame([$owner->id, str_repeat('a', 40)], [MigrationApproval::query()->sole()->approved_by, MigrationApproval::query()->sole()->revision]);
        $again = Build::query()->latest('id')->firstOrFail();
        $this->assertSame($build->id, $again->redeployed_from_build_id);
        $this->assertStringNotContainsString('--pretend', app(ArtisanCommandsScript::class)->script(7, $build->load('repository.website')), 'An approved revision migrates without the check.');

        // Run the check itself against a pretend output, with a stand-in php and curl.
        $start = (int) strpos($script, '# Migration safety');
        $check = substr($script, $start, (int) strpos($script, 'php artisan migrate --force') - $start);
        foreach (['alter table `orders` drop `legacy_total`' => 1, 'create table `invoices` (`id` bigint)' => 0] as $sql => $expected) {
            $bash = "set -e\nphp() { echo ".escapeshellarg($sql)."; }\ncurl() { :; }\n".$check."\necho passed\n";
            exec('bash -c '.escapeshellarg($bash).' 2>&1', $output, $code);
            $this->assertSame($expected, $code, implode("\n", $output));
        }
    }
}
