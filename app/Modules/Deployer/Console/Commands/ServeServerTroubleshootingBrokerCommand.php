<?php

namespace App\Modules\Deployer\Console\Commands;

use App\Modules\Deployer\Data\ServerTroubleshootingTerminalSize;
use App\Modules\Deployer\Enums\ServerTroubleshootingBrokerOutcome;
use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use App\Modules\Deployer\Services\ServerTroubleshootingBroker;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ServeServerTroubleshootingBrokerCommand extends Command
{
    protected $signature = 'buildpusher:troubleshooting:broker
        {session : Public troubleshooting session identifier}
        {--cycles= : Maximum polling cycles before the broker yields}
        {--poll-ms= : Milliseconds between polling cycles}
        {--columns=80 : Initial terminal column count}
        {--rows=24 : Initial terminal row count}';

    protected $description = 'Run a bounded supervisor-owned troubleshooting broker';

    /** Run only a bounded session window; supervisor restarts own continuation. */
    public function handle(ServerTroubleshootingBroker $broker): int
    {
        $session = ServerTroubleshootingSession::query()
            ->where('public_id', (string) $this->argument('session'))
            ->first();

        if (! $session) {
            $this->error('The troubleshooting session was not found.');

            return self::FAILURE;
        }

        try {
            $size = new ServerTroubleshootingTerminalSize(
                (int) $this->option('columns'),
                (int) $this->option('rows'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $result = $broker->run(
            $session,
            max(1, (int) getmypid()),
            $size,
            (int) ($this->option('cycles') ?? config('lessbuild.troubleshooting.broker_max_cycles', 600)),
            $this->option('poll-ms') === null ? null : (int) $this->option('poll-ms'),
        );

        $this->info("Troubleshooting broker ended with {$result->outcome->value} after {$result->cycles} cycle(s).");

        return $result->outcome === ServerTroubleshootingBrokerOutcome::Failed
            ? self::FAILURE
            : self::SUCCESS;
    }
}
