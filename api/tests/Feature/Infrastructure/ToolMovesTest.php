<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerProcess;
use App\Models\ToolMove;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ToolMovesTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * The project moving.
     *
     * @var Project
     */
    private Project $project;

    /**
     * The account owner.
     *
     * @var User
     */
    private User $owner;

    /**
     * The server sites move to.
     *
     * @var Server
     */
    private Server $server;

    /**
     * The moves page's address.
     *
     * @var string
     */
    private string $base;

    /**
     * Set up a project with a server, and a Forge account with one server and two sites.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        Queue::fake();
        $this->project = Project::factory()->withServices(['infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'pro');
        $this->server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $this->base = "/projects/{$this->project->id}/infrastructure/moves";
        Http::fake([
            'forge.laravel.com/api/v1/servers/7/sites/11/env' => Http::response("APP_NAME=Shop\nDB_DATABASE=shop\n"),
            'forge.laravel.com/api/v1/servers/7/sites/11/deployment/script' => Http::response("cd /home/forge/shop.example.com\ngit pull origin main\n"),
            'forge.laravel.com/api/v1/servers/7/sites/12/*' => Http::response('', 404),
            'forge.laravel.com/api/v1/servers/7/sites' => Http::response(['sites' => [
                ['id' => 11, 'name' => 'shop.example.com', 'directory' => '/public', 'repository' => 'acme/shop', 'repository_branch' => 'main', 'php_version' => 'php83'],
                ['id' => 12, 'name' => 'blog.example.com', 'directory' => '/public', 'repository' => null],
            ]]),
            'forge.laravel.com/api/v1/servers/7/jobs' => Http::response(['jobs' => [
                ['command' => 'php /home/forge/shop.example.com/artisan schedule:run', 'user' => 'forge', 'frequency' => 'minutely', 'cron' => '* * * * *'],
                ['command' => 'php /home/forge/shop.example.com/artisan backup:clean', 'user' => 'forge', 'frequency' => 'custom', 'cron' => 'every day'],
                ['command' => 'php /home/forge/blog.example.com/artisan schedule:run', 'user' => 'forge', 'frequency' => 'minutely', 'cron' => '* * * * *'],
            ]]),
            'forge.laravel.com/api/v1/servers/7/daemons' => Http::response(['daemons' => [
                ['command' => 'php artisan horizon', 'user' => 'forge', 'directory' => '/home/forge/shop.example.com', 'processes' => 1],
            ]]),
            'forge.laravel.com/api/v1/servers' => Http::response(['servers' => [['id' => 7, 'name' => 'forge-app', 'ip_address' => '198.51.100.7']]]),
        ]);
    }

    /**
     * Check a Forge account is read with a token, and moving a site creates its website here with the environment
     * file, plus its own cron jobs and daemon pointed at the new directory, skipping what doesn't fit.
     *
     * @return void
     */
    public function test_a_forge_site_moves_with_its_environment_cron_jobs_and_daemons(): void
    {
        $this->actingAs($this->owner)->post($this->base, ['source' => 'forge', 'token' => 'forge-token'])->assertRedirect()->assertSessionHas('status', 'Read 1 server and 2 sites from Laravel Forge.');
        $move = ToolMove::query()->sole();
        $this->assertSame('forge-token', $move->token);
        $this->assertStringNotContainsString('forge-token', (string) $move->getRawOriginal('token'));
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer forge-token'));

        $this->actingAs($this->owner)->get($this->base)->assertOk()->assertSee('shop.example.com')->assertSee('acme/shop @ main')
            ->assertSee('2 cron jobs')->assertSee('1 daemon')->assertSee('git pull origin main');

        $this->actingAs($this->owner)->post("{$this->base}/{$move->id}/sites", ['site' => '7-11', 'server_id' => $this->server->id])
            ->assertRedirect()->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'with 2 cron jobs and daemons') && str_contains($status, 'backup:clean'));

        $website = Website::query()->where('url', 'shop.example.com')->sole();
        $this->assertSame([$this->server->id, "APP_NAME=Shop\nDB_DATABASE=shop\n"], [$website->server_id, $website->env_file]);
        $cron = ServerCronJob::query()->sole();
        $this->assertSame(["php {$website->deploymentPath('current')}/artisan schedule:run", $this->server->name, '* * * * *'], [$cron->command, $cron->user, $cron->frequency]);
        $this->assertSame($website->deploymentPath('current'), ServerProcess::query()->sole()->directory);
        $this->assertSame(['7-11' => $website->id], $move->refresh()->moved);

        $this->actingAs($this->owner)->get($this->base)->assertSee('Moved: open the website');
        $this->actingAs($this->owner)->post("{$this->base}/{$move->id}/sites", ['site' => '7-11', 'server_id' => $this->server->id])->assertSessionHasErrors('site');
    }

    /**
     * Check a refused token is explained, members who can't create servers can't move, and forgetting a move removes
     * the token while moved websites stay.
     *
     * @return void
     */
    public function test_refused_tokens_permissions_and_forgetting(): void
    {
        Http::fake(['ploi.io/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);
        $this->actingAs($this->owner)->post($this->base, ['source' => 'ploi', 'token' => 'bad'])->assertSessionHasErrors(['token' => 'Ploi refused the API token. Create a new one with read access and try again.']);
        $this->assertSame(0, ToolMove::query()->count());

        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->get($this->base)->assertForbidden();
        $this->actingAs($viewer)->post($this->base, ['source' => 'forge', 'token' => 'x'])->assertForbidden();

        $this->actingAs($this->owner)->post($this->base, ['source' => 'forge', 'token' => 'forge-token']);
        $move = ToolMove::query()->sole();
        $this->actingAs($this->owner)->delete("{$this->base}/{$move->id}")->assertRedirect();
        $this->assertSame(0, ToolMove::query()->count());
    }
}
