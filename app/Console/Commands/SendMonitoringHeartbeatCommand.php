<?php

namespace App\Console\Commands;

use App\Services\ExternalMonitoring;
use Illuminate\Console\Command;

class SendMonitoringHeartbeatCommand extends Command
{
    protected $signature = 'buildpusher:monitoring:heartbeat
        {--verify-status : Also verify that the independently hosted status page is reachable}';

    protected $description = 'Notify the configured independent monitor that the scheduler is running';

    public function handle(ExternalMonitoring $monitoring): int
    {
        if (blank(config('monitoring.heartbeat_url'))) {
            $this->warn('External monitoring heartbeat is not configured.');

            return self::FAILURE;
        }

        if (config('app.env') === 'production' && ! $monitoring->configurationCheck()['passed']) {
            $this->error('External monitoring requires secure, independently hosted destinations.');

            return self::FAILURE;
        }

        if (! $monitoring->sendHeartbeat()) {
            $this->error('The external monitoring heartbeat failed.');

            return self::FAILURE;
        }

        $this->info('External monitoring heartbeat delivered.');

        if ($this->option('verify-status')) {
            if (! $monitoring->statusPageIsReachable()) {
                $this->error('The independent status page could not be reached.');

                return self::FAILURE;
            }

            $this->info('Independent status page is reachable.');
        }

        return self::SUCCESS;
    }
}
