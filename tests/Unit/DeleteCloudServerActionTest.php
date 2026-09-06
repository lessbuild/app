<?php

namespace Tests\Unit;

use App\Actions\Server\DeleteCloudServerAction;
use App\Contracts\ServerProvider;
use App\Models\Provider;
use App\Models\Server;
use App\Services\ServerProviderResolver;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DeleteCloudServerActionTest extends TestCase
{
    public function test_it_deletes_the_server_before_its_login_key(): void
    {
        $server = $this->server();
        $provider = Mockery::mock(ServerProvider::class);
        $provider->shouldReceive('deleteServer')->once()->ordered()->with('instance-1')->andReturnTrue();
        $provider->shouldReceive('deleteSshKey')->once()->ordered()->with('key-1')->andReturnTrue();

        (new DeleteCloudServerAction($this->resolver($server, $provider)))->handle($server);

        $this->addToAssertionCount(1);
    }

    public function test_it_keeps_the_login_key_when_server_deletion_fails(): void
    {
        $server = $this->server();
        $provider = Mockery::mock(ServerProvider::class);
        $provider->shouldReceive('name')->once()->andReturn('Test Cloud');
        $provider->shouldReceive('deleteServer')->once()->with('instance-1')->andReturnFalse();
        $provider->shouldNotReceive('deleteSshKey');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test Cloud could not delete the cloud server.');

        (new DeleteCloudServerAction($this->resolver($server, $provider)))->handle($server);
    }

    private function server(): Server
    {
        $provider = new Provider(['provider' => Provider::TYPE_DIGITALOCEAN]);
        $provider->id = 10;

        $server = new Server([
            'identifier' => 'instance-1',
            'ssh_fingerprint' => 'key-1',
            'ssh_key_owned' => true,
        ]);
        $server->setRelation('provider', $provider);

        return $server;
    }

    private function resolver(Server $server, ServerProvider $provider): ServerProviderResolver
    {
        $resolver = Mockery::mock(ServerProviderResolver::class);
        $resolver->shouldReceive('resolve')->once()->with($server->provider)->andReturn($provider);

        return $resolver;
    }
}
