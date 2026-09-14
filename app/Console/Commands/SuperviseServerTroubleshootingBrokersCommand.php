<?php

namespace App\Console\Commands;

use App\Actions\Server\RevokeServerTroubleshootingSessionAction;
use App\Models\ServerTroubleshootingSession;
use App\Policies\ServerTroubleshootingSessionPolicy;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class SuperviseServerTroubleshootingBrokersCommand extends Command
{
    protected $signature = 'buildpusher:troubleshooting:supervise
        {--limit=100 : Maximum active sessions to inspect}
        {--dry-run : Report eligible broker units without starting systemd}';

    protected $description = 'Start supervised brokers for active troubleshooting sessions';

    /**
     * Start only eligible UUID-addressed broker units.
     *
     * The systemd template owns the local process cgroup. Session leases,
     * actor revalidation and remote connection policy remain in the broker and
     * its actions; this command only bridges bounded database discovery to
     * systemd without shell interpolation.
     */
    public function handle(
        ServerTroubleshootingSessionPolicy $sessions,
        RevokeServerTroubleshootingSessionAction $revoke,
    ): int {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $now = now();
        $candidates = ServerTroubleshootingSession::query()
            ->whereIn('status', ServerTroubleshootingSession::ACTIVE_STATUSES)
            ->where('expires_at', '>', $now)
            ->where('idle_expires_at', '>', $now)
            ->where(function ($query) use ($now): void {
                $query
                    ->whereNull('broker_lease_expires_at')
                    ->orWhere('broker_lease_expires_at', '<=', $now);
            })
            ->with(['server', 'user'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $started = 0;
        $revoked = 0;
        $failed = 0;

        foreach ($candidates as $session) {
            if (! $session->user || ! $sessions->connect($session->user, $session)) {
                if ($revoke->handle($session)) {
                    $revoked++;
                }

                continue;
            }

            $unit = 'lessbuild-troubleshooting-broker@'.$session->public_id.'.service';
            if ($this->option('dry-run')) {
                $this->line("Would start {$unit}.");
                $started++;

                continue;
            }

            $process = new Process(['systemctl', 'start', '--no-block', $unit]);
            $process->setTimeout(15);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->error("Unable to start {$unit}.");
                $failed++;

                continue;
            }

            $started++;
        }

        $this->info("Started {$started} troubleshooting broker(s); revoked {$revoked} ineligible session(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
