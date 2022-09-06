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
     * Execute the console command.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function handle()
    {
        $server = Server::find($this->argument('server_id'));

        (new InitialiseServerJob($server))->handle();
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
