<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

class Repository extends Command
{
    /**
     * Place to save cloned repositories
     *
     * @var string
     */
    protected string $storage = './storage/repositories/';

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'lessbuild:repository
        {repository : The repository to clone}
        {branch=main : The branch to clone}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Clone a git repo repository';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $repository = $this->argument('repository');
        $branch = $this->argument('branch');

        $name = ($config['name'] ?? Str::random('16'));
        $location = $this->storage.$name;

        $this->task('Removing old repository', function () use ($location, $process) {
            $process->execute([
                '',
            ]);
            if (is_dir($location)) {
                shell_exec("rm -rf {$location}");
            }
        });

        $this->task('Configuring Deploy.yaml', function () use ($config, $name, $repository) {
            $yaml = Yaml::dump(array_merge($config, [
                'name' => $name,
                'repository' => $repository,
            ]));

            file_put_contents('./deploy.yaml', $yaml);
        });

        $this->task('Cloning repository', function () use ($repository, $location) {
            shell_exec("git clone {$repository} {$location}");
        });

        $this->task('Checking out branch', function () use ($location, $branch) {
            shell_exec("git -C $location checkout {$branch}");
        });
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
