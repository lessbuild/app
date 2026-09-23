<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\AlertRule;
use Illuminate\Database\Seeder;

class AlertRuleSeeder extends Seeder
{
    public function run(): void
    {
        AlertRule::factory()->paused()->create(['name' => 'Example API error rate (paused)']);
    }
}
