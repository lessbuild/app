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
     * Dispatch website provisioning synchronously through Laravel's job lifecycle; reject missing targets before dispatch.
     *
     * Execute the console command.
     *
     * @return int Failure when the website is absent, otherwise success after synchronous dispatch; provisioning errors propagate.
     *
     * @throws \Exception
     */
    public function handle(): int
    {
        $website = Website::find($this->argument('website_id'));
        if (! $website) {
            $this->error('Website not found.');

            return self::FAILURE;
        }

        AddWebsiteJob::dispatchSync($website);

        return self::SUCCESS;
    }

    /**
     * Leave automatic scheduling disabled for this manually invoked command.
     *
     * Define the command's schedule.
     *
     * @param  Schedule  $schedule  Scheduler supplied by command discovery; this command currently registers no recurring task.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
