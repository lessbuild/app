<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\Release;
use Illuminate\Database\Seeder;

class ReleaseSeeder extends Seeder
{
    public function run(): void
    {
        Release::factory()->create(['version' => 'example-release']);
    }
}
