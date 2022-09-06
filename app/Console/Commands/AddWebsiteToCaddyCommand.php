<?php

namespace App\Console\Commands;

use App\Jobs\Web\AddWebsiteJob;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

class AddWebsiteToCaddyCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'lessbuild:server:add-website
        {website_id : The id of the website}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Add website to caddy';

    /**
     * Execute the console command.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function handle()
    {
        $website = Website::find($this->argument('website_id'));

        (new AddWebsiteJob($website))->handle();
    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
