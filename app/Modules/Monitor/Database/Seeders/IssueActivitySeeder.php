<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Models\IssueActivity;
use Illuminate\Database\Seeder;

class IssueActivitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $issue = Issue::factory()->create(['title' => 'Example: payment provider timed out']);
        IssueActivity::factory()->for($issue)->create();
    }
}
