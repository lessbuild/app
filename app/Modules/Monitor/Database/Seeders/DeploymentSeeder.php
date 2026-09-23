<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\Deployment;
use Illuminate\Database\Seeder;

class DeploymentSeeder extends Seeder
{
    public function run(): void
    {
        Deployment::factory()->create(['note' => 'Example completed deployment']);
    }
}
