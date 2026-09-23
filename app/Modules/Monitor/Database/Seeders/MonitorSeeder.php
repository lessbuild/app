<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Monitor;
use Illuminate\Database\Seeder;
use LogicException;

class MonitorSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Example monitors are only available in local or test environments.');
        }
        $environment = Environment::query()->orderBy('id')->first();
        if ($environment !== null && ! Monitor::query()->whereBelongsTo($environment)->exists()) {
            Monitor::factory()->for($environment)->paused()->create(['name' => 'Example HTTP check (paused)']);
        }
    }
}
