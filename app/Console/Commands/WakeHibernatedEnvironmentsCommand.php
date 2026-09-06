<?php

namespace App\Console\Commands;

use App\Jobs\WakeHibernatedEnvironmentJob;
use App\Models\Environment;
use Illuminate\Console\Command;

class WakeHibernatedEnvironmentsCommand extends Command
{
    protected $signature = 'buildpusher:environments:wake';

    protected $description = 'Wake hibernated environments after an incoming request';

    public function handle(): int
    {
        Environment::query()->whereNotNull('hibernated_at')->pluck('id')
            ->each(fn (int $id) => WakeHibernatedEnvironmentJob::dispatch($id));

        return self::SUCCESS;
    }
}
