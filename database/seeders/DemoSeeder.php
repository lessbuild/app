<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public const EMAIL = 'ncorkish@icloud.com';

    public const PREFIX = '[Demo] ';

    public function run(): void
    {
        $this->call([
            DemoAccountSeeder::class,
            DemoInfrastructureSeeder::class,
            DemoApplicationsSeeder::class,
            DemoGallerySeeder::class,
            DemoOperationsSeeder::class,
        ]);
    }
}
