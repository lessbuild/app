<?php

namespace Tests\Feature;

use App\Contracts\ServerProvider;
use App\Services\DigitalOcean;
use App\Services\HetznerCloud;
use App\Services\Vultr;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerProviderContractTest extends TestCase
{
    public function test_all_server_providers_treat_absent_resources_as_success_and_other_failures_as_failure(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.digitalocean.com/v2/droplets/*' => $this->deletionSequence(),
            'https://api.digitalocean.com/v2/account/keys/*' => $this->deletionSequence(),
            'https://api.hetzner.cloud/v1/servers/*' => $this->deletionSequence(),
            'https://api.hetzner.cloud/v1/ssh_keys/*' => $this->deletionSequence(),
            'https://api.vultr.com/v2/instances/*' => $this->deletionSequence(),
            'https://api.vultr.com/v2/ssh-keys/*' => $this->deletionSequence(),
        ]);

        $providers = [
            new DigitalOcean('digitalocean-secret'),
            new HetznerCloud('hetzner-secret'),
            new Vultr('vultr-secret'),
        ];

        foreach ($providers as $provider) {
            $this->assertInstanceOf(ServerProvider::class, $provider);
            $this->assertTrue($provider->deleteServer('server-1'));
            $this->assertFalse($provider->deleteServer('server-1'));
            $this->assertTrue($provider->deleteSshKey('key-1'));
            $this->assertFalse($provider->deleteSshKey('key-1'));
        }

        Http::assertSentCount(12);
    }

    private function deletionSequence(): ResponseSequence
    {
        return Http::sequence()
            ->push([], 404)
            ->push([], 503);
    }
}
