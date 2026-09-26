<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Actions\Server\ClaimServerTroubleshootingBrokerLeaseAction;
use App\Modules\Deployer\Actions\Server\MarkServerTroubleshootingSessionConnectedAction;
use App\Modules\Deployer\Actions\Server\ReleaseServerTroubleshootingBrokerLeaseAction;
use App\Modules\Deployer\Actions\Server\RenewServerTroubleshootingBrokerLeaseAction;
use App\Modules\Deployer\Contracts\ServerTroubleshootingTransport;
use App\Modules\Deployer\Data\ServerTroubleshootingBrokerResult;
use App\Modules\Deployer\Data\ServerTroubleshootingTerminalSize;
use App\Modules\Deployer\Enums\ServerTroubleshootingBrokerOutcome;
use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use Throwable;

class ServerTroubleshootingBroker
{
    public function __construct(
        private readonly ServerTroubleshootingTransport $transport,
        private readonly ClaimServerTroubleshootingBrokerLeaseAction $claim,
        private readonly MarkServerTroubleshootingSessionConnectedAction $connected,
        private readonly RenewServerTroubleshootingBrokerLeaseAction $renew,
        private readonly ReleaseServerTroubleshootingBrokerLeaseAction $release,
        private readonly ServerTroubleshootingFrameStore $frames,
    ) {}

    /**
     * Run a bounded supervisor-owned polling window for one session.
     *
     * A broker checks its database lease before each poll. Input is claimed
     * before it is written to avoid replaying shell input after a crash; output
     * is persisted as encrypted, sequenced frames for a later reader.
     */
    public function run(
        ServerTroubleshootingSession $session,
        int $processId,
        ServerTroubleshootingTerminalSize $size,
        int $cycles = 1,
        ?int $pollMilliseconds = null,
    ): ServerTroubleshootingBrokerResult {
        $cycles = max(1, min($this->maximumCycles(), $cycles));
        $pollMilliseconds = max(
            10,
            min(2000, $pollMilliseconds ?? (int) config('lessbuild.troubleshooting.broker_poll_milliseconds', 100)),
        );
        $lease = $this->claim->handle($session, $processId);

        if ($lease === null) {
            return new ServerTroubleshootingBrokerResult(ServerTroubleshootingBrokerOutcome::NotClaimed, 0);
        }

        $connection = null;
        $failed = false;
        $completedCycles = 0;
        $outcome = ServerTroubleshootingBrokerOutcome::Released;

        try {
            $connection = $this->transport->connect($lease->session->server, $size);
            if (! $this->connected->handle($lease)) {
                return new ServerTroubleshootingBrokerResult(ServerTroubleshootingBrokerOutcome::NotClaimed, 0);
            }

            for ($cycle = 0; $cycle < $cycles; $cycle++) {
                if (! $this->renew->handle($lease)) {
                    $outcome = $this->terminalOutcome($lease->session);
                    break;
                }

                $input = $this->frames->claimNextInput($lease);
                if ($input !== null) {
                    $connection->write($input->payload);
                }

                $output = $connection->read();
                if ($output !== '') {
                    $this->frames->appendOutput($lease, $output);
                }

                $completedCycles++;
                if (! $connection->isRunning()) {
                    $failed = true;
                    $outcome = ServerTroubleshootingBrokerOutcome::Failed;
                    break;
                }

                if ($cycle + 1 < $cycles) {
                    usleep($pollMilliseconds * 1000);
                }
            }
        } catch (Throwable) {
            $failed = true;
            $outcome = ServerTroubleshootingBrokerOutcome::Failed;
        } finally {
            $connection?->close();
            $this->release->handle($lease, $failed);
        }

        if ($failed) {
            $outcome = ServerTroubleshootingBrokerOutcome::Failed;
        }

        return new ServerTroubleshootingBrokerResult($outcome, $completedCycles);
    }

    private function terminalOutcome(ServerTroubleshootingSession $session): ServerTroubleshootingBrokerOutcome
    {
        $status = $session->fresh()?->status;

        return match ($status) {
            ServerTroubleshootingSession::STATUS_EXPIRED => ServerTroubleshootingBrokerOutcome::Expired,
            ServerTroubleshootingSession::STATUS_REVOKED,
            ServerTroubleshootingSession::STATUS_CLOSED => ServerTroubleshootingBrokerOutcome::Released,
            ServerTroubleshootingSession::STATUS_FAILED => ServerTroubleshootingBrokerOutcome::Failed,
            default => ServerTroubleshootingBrokerOutcome::Failed,
        };
    }

    private function maximumCycles(): int
    {
        return max(1, min(100000, (int) config('lessbuild.troubleshooting.broker_max_cycles', 600)));
    }
}
