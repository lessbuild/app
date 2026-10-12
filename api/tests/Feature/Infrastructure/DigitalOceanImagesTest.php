<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\ProviderType;
use App\Models\Provider;
use App\Services\Infrastructure\DigitalOcean;
use App\Services\Infrastructure\ServerCatalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DigitalOceanImagesTest extends TestCase
{
    /**
     * DigitalOcean is asked for distributions (the query string isn't dropped), and the form only offers plain Ubuntu
     * 24.04 and 22.04: never a one-click app built on Ubuntu, nor an older release.
     */
    public function test_only_plain_supported_ubuntu_images_are_offered(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/images*' => Http::response(['images' => [
                ['slug' => 'ubuntu-24-04-x64', 'distribution' => 'Ubuntu', 'name' => '24.04 (LTS) x64', 'type' => 'base'],
                ['slug' => 'ubuntu-22-04-x64', 'distribution' => 'Ubuntu', 'name' => '22.04 (LTS) x64', 'type' => 'base'],
                ['slug' => 'ubuntu-20-04-x64', 'distribution' => 'Ubuntu', 'name' => '20.04 (LTS) x64', 'type' => 'base'],
                ['slug' => 'akaunting-20-04', 'distribution' => 'Ubuntu', 'name' => 'Akaunting on Ubuntu 20.04', 'type' => 'application'],
                ['slug' => 'logstore', 'distribution' => 'Ubuntu', 'name' => 'log-store 0.6.3 on Ubuntu 22.04', 'type' => 'application'],
            ]]),
            'api.digitalocean.com/v2/sizes*' => Http::response(['sizes' => []]),
            'api.digitalocean.com/v2/regions*' => Http::response(['regions' => []]),
        ]);
        $provider = new Provider;
        $provider->forceFill(['type' => ProviderType::DigitalOcean]);

        $catalog = app(ServerCatalog::class)->for($provider, new DigitalOcean('token'));

        $this->assertSame(['ubuntu-22-04-x64', 'ubuntu-24-04-x64'], array_column($catalog['images'], 'id'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v2/images') && str_contains($request->url(), 'type=distribution') && str_contains($request->url(), 'per_page=200'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v2/sizes?per_page=200'));
    }
}
