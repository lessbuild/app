<?php

namespace App\Console\Commands;

use App\Jobs\Server\InitialiseServerJob;
use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

class InitialiseServerCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'lessbuild:server:initialise
        {server_id : The id of the server}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Initialise the server';

    /**
     * Run initialization synchronously for the server_id argument; reject missing targets before dispatch.
     *
     * Execute the console command.
     *
     * @return int Failure when the server is absent, otherwise success after synchronous dispatch; initialization errors propagate.
     *
     * @throws \Exception
     */
    public function handle(): int
    {
        $server = Server::find($this->argument('server_id'));
        if (! $server) {
            $this->error('Server not found.');

            return self::FAILURE;
        }

        InitialiseServerJob::dispatchSync($server);

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
