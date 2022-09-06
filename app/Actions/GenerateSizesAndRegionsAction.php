<?php

namespace App\Actions;

use App\Models\Region;
use App\Models\Size;
use App\Services\DigitalOcean;
use Illuminate\Support\Env;

class GenerateSizesAndRegionsAction
{
    /**
     * @return void
     *
     * @throws \Exception
     */
    public function handle()
    {
        $digitalOcean = new DigitalOcean(Env::get('DO_KEY'));

        $regions = $digitalOcean->getRegions();
        foreach ($regions as $region) {
            Region::updateOrCreate([
                'name' => $region['name'],
                'slug' => $region['slug'],
            ], [
                'name' => $region['name'],
                'slug' => $region['slug'],
            ]);
        }

        $sizes = $digitalOcean->getSizes();

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
