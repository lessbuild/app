<?php

namespace Tests\Feature;

use App\Jobs\Web\AddWebsiteJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebsitePlacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_website_forms_only_offer_active_web_capable_servers(): void
    {
        $user = User::factory()->create();
        $application = $this->server($user, 'Application', ServerTypeEnum::app, Server::STATUS_ACTIVE);
        $secondApplication = $this->server($user, 'Second Application', ServerTypeEnum::app, Server::STATUS_ACTIVE);
        $this->server($user, 'Web', ServerTypeEnum::web, Server::STATUS_ACTIVE);
        $this->server($user, 'Worker', ServerTypeEnum::worker, Server::STATUS_ACTIVE);
        $this->server($user, 'Pending', ServerTypeEnum::app, Server::STATUS_PROVISIONING);

        $this->actingAs($user)->get(route('websites.create'))
            ->assertSuccessful()
            ->assertSee('Application (App)')
            ->assertSee('Second Application (App)')
            ->assertDontSee('Web (Web)')
            ->assertDontSee('Worker (Worker)')
            ->assertDontSee('Pending (App)');

        $website = $this->website($user, $application);
        $this->actingAs($user)->get(route('websites.edit', $website))
            ->assertSuccessful()
            ->assertSeeInOrder(['value="'.$application->id.'"', 'selected'], false)
            ->assertSee('value="'.$secondApplication->id.'"', false);
    }

    public function test_website_creation_requires_an_active_web_capable_server(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $worker = $this->server($user, 'Worker', ServerTypeEnum::worker, Server::STATUS_ACTIVE);
        $pending = $this->server($user, 'Pending', ServerTypeEnum::app, Server::STATUS_PROVISIONING);

        foreach ([$worker, $pending] as $server) {
            $this->actingAs($user)->post(route('websites.store'), $this->payload($server))
                ->assertSessionHasErrors(['server_id']);
        }

        $this->assertDatabaseCount('websites', 0);
        Queue::assertNothingPushed();
    }

    public function test_updating_a_website_moves_it_to_the_selected_ready_server(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $oldServer = $this->server($user, 'Old', ServerTypeEnum::app, Server::STATUS_ACTIVE);
        $newServer = $this->server($user, 'New', ServerTypeEnum::app, Server::STATUS_ACTIVE);
        $website = $this->website($user, $oldServer);

        $this->actingAs($user)->patch(route('websites.update', $website), [
            ...$this->payload($newServer),
            'name' => 'Updated Site',
        ])->assertRedirect(route('websites.show', $website));

        $website->refresh();
        $this->assertTrue($website->server->is($newServer));
        $this->assertSame($oldServer->id, $website->previous_server_id);
        $this->assertSame('updated site', $website->name);
        $this->assertSame(Website::STATUS_QUEUED, $website->provisioning_status);
        Queue::assertPushed(AddWebsiteJob::class, fn (AddWebsiteJob $job): bool => $job->website->is($website));
    }

    private function server(User $user, string $name, ServerTypeEnum $type, string $status): Server
    {
        return $user->servers()->create([
            'name' => $name,
            'type' => $type,
            'provisioning_status' => $status,
            'mysql_root_password' => $type === ServerTypeEnum::app ? 'mysql-root-secret' : null,
        ]);
    }

    private function website(User $user, Server $server): Website
    {
        return $user->websites()->create([
            ...$this->payload($server),
            'database_password' => 'database-secret',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
    }

    private function payload(Server $server): array
    {
        return [
            'server_id' => $server->id,
            'name' => 'Example Site',
            'url' => 'example.test',
            'description' => 'Example website',
            'environment' => 'APP_ENV=production',
        ];
    }
}
