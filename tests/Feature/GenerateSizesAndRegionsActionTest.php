<?php

namespace Tests\Feature;

use App\Modules\Deployer\Actions\GenerateSizesAndRegionsAction;
use App\Modules\Deployer\Contracts\ServerProvider;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Size;
use App\Modules\Deployer\Services\ServerProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GenerateSizesAndRegionsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_refresh_records_when_price_data_was_observed(): void
    {
        $client = Mockery::mock(ServerProvider::class);
        $client->shouldReceive('regions')->once()->andReturn([
            ['name' => 'New York', 'slug' => 'nyc1'],
        ]);
        $client->shouldReceive('sizes')->once()->andReturn([
            [
                'slug' => 's-1',
                'description' => 'Small',
                'memory' => 1024,
                'vcpus' => 1,
                'disk' => 25,
                'transfer' => 1,
                'price_monthly' => 12,
                'price_hourly' => 0.02,
                'regions' => ['nyc1'],
            ],
        ]);
        $resolver = Mockery::mock(ServerProviderResolver::class);
        $resolver->shouldReceive('resolveCredentials')
            ->once()
            ->with(Provider::TYPE_DIGITALOCEAN, Mockery::type('string'))
            ->andReturn($client);
        $this->app->instance(ServerProviderResolver::class, $resolver);

        app(GenerateSizesAndRegionsAction::class)->handle();

        $size = Size::query()->where('slug', 's-1')->firstOrFail();
        $this->assertNotNull($size->catalog_synced_at);
        $this->assertSame(12.0, (float) $size->price_monthly);
    }
}
