<?php

namespace App\Actions;

use App\Models\Provider;
use App\Models\Region;
use App\Models\Size;
use App\Services\ServerProviderResolver;
use Illuminate\Support\Env;

class GenerateSizesAndRegionsAction
{
    public function __construct(private readonly ServerProviderResolver $providers) {}

    /**
     * @return void
     *
     * @throws \Exception
     */
    public function handle()
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
