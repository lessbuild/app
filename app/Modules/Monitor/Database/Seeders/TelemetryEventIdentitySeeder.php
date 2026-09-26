<?php

namespace App\Modules\Monitor\Database\Seeders;

use Illuminate\Database\Seeder;

class TelemetryEventIdentitySeeder extends Seeder
{
    public function run(): void
    {
        $this->callOnce(IngestReceiptSeeder::class);
    }
}
