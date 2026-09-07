<?php

namespace App\Actions;

use App\Models\Provider;
use App\Models\Region;
use App\Models\Size;
use App\Services\ServerProviderResolver;
use Illuminate\Support\Env;

class GenerateSizesAndRegionsAction
{
    /**
     * Use the provider resolver to read the DigitalOcean catalog.
     *
     * @param  ServerProviderResolver  $providers  Resolver for authenticated server-provider clients.
     */
    public function __construct(private readonly ServerProviderResolver $providers) {}

    /**
     * Refresh stored DigitalOcean sizes and regions and attach their availability relationships; provider failures propagate.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function handle(): void
    {
        $provider = $this->providers->resolveCredentials(Provider::TYPE_DIGITALOCEAN, (string) Env::get('DO_KEY'));

        $regions = $provider->regions();
        foreach ($regions as $region) {
            Region::updateOrCreate([
                'name' => $region['name'],
                'slug' => $region['slug'],
            ], [
                'name' => $region['name'],
                'slug' => $region['slug'],
            ]);
        }

        $sizes = $provider->sizes();

        foreach ($sizes as $size) {
            $created = Size::updateOrCreate([
                'slug' => $size['slug'],
            ], [
                'slug' => $size['slug'],
                'memory' => $size['memory'],
                'vcpus' => $size['vcpus'],
                'disk' => $size['disk'],
                'transfer' => $size['transfer'],
                'price_monthly' => $size['price_monthly'],
                'price_hourly' => $size['price_hourly'],
                'description' => $size['description'],
            ]);

            foreach ($size['regions'] as $key => $value) {
                $region = Region::where('slug', $value)->first();

                $created->regions()->detach($region);
                $created->regions()->attach($region);
            }
        }
    }
}
