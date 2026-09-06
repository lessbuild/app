<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;

class ValidateCandidateScript extends BuildProvisioningScript
{
    public static string $title = 'Validate canary candidate';

    public static string $description = 'Exercise the candidate release over loopback before it receives production traffic';

    public static string $identifier = 'validated-canary-candidate';

    public function script(int $step, Build $build): string
    {
        $runtime = $build->environment_payload['runtime'] ?? [];
        $progress = $this->progress($step, $build);
        if (($runtime['deployment_strategy'] ?? 'blue_green') !== 'canary' || ($runtime['type'] ?? 'php') !== 'php') {
            return "\n# Canary validation not selected\n{$progress}\n";
        }
        $website = $build->repository->website;
        $setup = escapeshellarg("/var/www/{$website->deployment_slug}/setup");
        $healthPath = escapeshellarg($website->health_check_enabled ? $website->health_check_path : '/');
        $host = escapeshellarg($website->url);
        $port = 20000 + ($build->id % 20000);

        return <<<SCRIPT
        CANDIDATE_PATH={$setup}
        CANARY_PATH={$healthPath}
        CANARY_HOST={$host}
        CANARY_PORT={$port}
        php -S "127.0.0.1:\$CANARY_PORT" -t "\$CANDIDATE_PATH/public" > /tmp/buildpusher-canary-{$build->id}.log 2>&1 &
        CANARY_PID=\$!
        cleanup_canary() { kill "\$CANARY_PID" 2>/dev/null || true; wait "\$CANARY_PID" 2>/dev/null || true; rm -f -- /tmp/buildpusher-canary-{$build->id}.log; }
        trap cleanup_canary EXIT
        canary_ready=0
        for attempt in 1 2 3 4 5; do
            if curl --fail --silent --show-error --connect-timeout 2 --max-time 10 --header "Host: \$CANARY_HOST" --output /dev/null "http://127.0.0.1:\$CANARY_PORT\$CANARY_PATH"; then
                canary_ready=1
                break
            fi
            sleep 1
        done
        if [ "\$canary_ready" -ne 1 ]; then
            DEPLOYMENT_FAILURE_MESSAGE="Canary candidate health validation failed"
            cleanup_canary
            false
        fi
        cleanup_canary
        trap - EXIT
        {$progress}
        SCRIPT;
    }
}
