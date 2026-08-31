<?php

namespace Tests\Feature;

use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_set_and_clear_a_display_name_without_changing_the_cloud_hostname(): void
    {
        [$owner, $server] = $this->server('cloud-hostname');
        $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Customer portal',
            'url' => 'customer.example.test',
            'description' => 'Customer website',
            'environment' => 'APP_ENV=production',
        ]);

        $this->actingAs($owner)->get(route('servers.edit', $server))
            ->assertSuccessful()
            ->assertSee('Server display name')
            ->assertSee('cloud-hostname')
            ->assertSee(route('servers.update', $server));

        $this->patch(route('servers.update', $server), [
            'display_name' => "  Customer   Edge\nPrimary  ",
            'name' => 'attempted-cloud-rename',
            'provider_id' => 999999,
        ])->assertRedirect(route('servers.show', $server))
            ->assertSessionHas('success', 'Server display name updated.');

        $server->refresh();
        $this->assertSame('Customer Edge Primary', $server->display_name);
        $this->assertSame('Customer Edge Primary', $server->label);
        $this->assertSame('cloud-hostname', $server->name);
        $this->assertDatabaseHas('events', [
            'user_id' => $owner->id,
            'parentable_type' => Server::class,
            'parentable_id' => $server->id,
            'category' => 'server',
            'event' => 'Server display name changed from "cloud-hostname" to "Customer Edge Primary".',
        ]);

        foreach ([
            route('servers.index'),
            route('servers.show', $server),
            route('websites.index'),
            route('dashboard'),
        ] as $url) {
            $this->get($url)
                ->assertSuccessful()
                ->assertSee('Customer Edge Primary');
        }
        $this->get(route('servers.index', ['search' => 'Customer Edge']))
            ->assertSuccessful()
            ->assertViewHas('servers', fn ($servers): bool => $servers->count() === 1
                && $servers->sole()->is($server));
        $this->get(route('search.index', ['q' => 'Customer Edge']))
            ->assertSuccessful()
            ->assertSee('Customer Edge Primary')
            ->assertSee(route('servers.show', $server));

        $this->patch(route('servers.update', $server), ['display_name' => '   '])
            ->assertRedirect(route('servers.show', $server));
        $server->refresh();
        $this->assertNull($server->display_name);
        $this->assertSame('cloud-hostname', $server->label);
        $this->assertDatabaseHas('events', [
            'user_id' => $owner->id,
            'category' => 'server',
            'event' => 'Server display name changed from "Customer Edge Primary" to "cloud-hostname".',
        ]);
    }

    public function test_display_name_validation_and_ownership_are_enforced(): void
    {
        [$owner, $server] = $this->server('owned-hostname');
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('servers.edit', $server))->assertForbidden();
        $this->patch(route('servers.update', $server), ['display_name' => str_repeat('x', 81)])
            ->assertForbidden();
        $this->assertNull($server->fresh()->display_name);

        $this->actingAs($owner)->from(route('servers.edit', $server))
            ->patch(route('servers.update', $server), ['display_name' => str_repeat('x', 81)])
            ->assertRedirect(route('servers.edit', $server))
            ->assertSessionHasErrors('display_name');
        $this->assertNull($server->fresh()->display_name);

        $this->patch(route('servers.update', $server), ['display_name' => '0'])
            ->assertRedirect(route('servers.show', $server));
        $this->assertSame('0', $server->fresh()->label);

        $this->patch(route('servers.update', $server), ['display_name' => 'owned-hostname'])
            ->assertRedirect(route('servers.show', $server));
        $this->assertNull($server->fresh()->display_name);
        $this->assertDatabaseMissing('events', [
            'user_id' => $owner->id,
            'event' => 'Server display name changed from "owned-hostname" to "owned-hostname".',
        ]);
    }

    /** @return array{User, Server} */
    private function server(string $hostname): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'DigitalOcean',
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'provider-token',
            'description' => 'Cloud provider',
        ]);
        $server = $owner->servers()->create([
            'provider_id' => $provider->id,
            'name' => $hostname,
            'type' => ServerTypeEnum::app,
            'provisioning_status' => Server::STATUS_ACTIVE,
            'public_ip' => '203.0.113.10',
            'mysql_root_password' => 'demo-database-password',
        ]);

        return [$owner, $server];
    }
}
