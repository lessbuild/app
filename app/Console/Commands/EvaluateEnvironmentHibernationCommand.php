<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateEnvironmentHibernationJob;
use App\Models\Environment;
use Illuminate\Console\Command;

class EvaluateEnvironmentHibernationCommand extends Command
{
    protected $signature = 'buildpusher:environments:hibernate';

    protected $description = 'Evaluate inactivity-based environment hibernation';

    /**
     * Queue idle evaluations for environments with a hibernation threshold that are not currently sleeping.
     *
     * @return int SUCCESS after queuing evaluations.
     */
    public function handle(): int
    {
        Environment::query()->whereNotNull('hibernate_after_minutes')->whereNull('hibernated_at')->pluck('id')
            ->each(fn (int $id) => EvaluateEnvironmentHibernationJob::dispatch($id));

        return self::SUCCESS;
    }
}
