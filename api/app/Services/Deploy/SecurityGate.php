<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Build;
use App\Services\Infrastructure\ProvisioningCallbackUrl;

/**
 * The Security gate's part of a deploy script: after dependencies are installed, send the new release's lock files to
 * Security and stop the deploy (before it goes live) if the answer is to block. It runs inside the install stage, so
 * the stage numbers deploy callbacks report stay the same.
 */
final class SecurityGate
{
    /**
     * Render the gate's commands, or a comment when the environment has no gate. When Security can't be reached, the
     * deploy carries on, so an outage never holds releases up.
     *
     * @param  Build  $build
     * @return string
     */
    public function commands(Build $build): string
    {
        if (! in_array($build->environment_payload['security_gate'] ?? null, ['critical', 'high'], true)) {
            return '# No Security gate on this environment';
        }
        $setup = escapeshellarg($build->deploymentPath('setup'));
        $url = escapeshellarg(ProvisioningCallbackUrl::buildSecurityGate($build));

        return <<<SCRIPT
            # Security gate: known vulnerabilities in the new release's packages
            SECURITY_RELEASE={$setup}
            security_args=()
            [ -f "\$SECURITY_RELEASE/composer.lock" ] && security_args+=(-F "composer=@\$SECURITY_RELEASE/composer.lock")
            [ -f "\$SECURITY_RELEASE/package-lock.json" ] && security_args+=(-F "npm=@\$SECURITY_RELEASE/package-lock.json")
            if [ "\${#security_args[@]}" -gt 0 ]; then
                security_verdict="$(curl --silent --show-error --max-time 120 "\${security_args[@]}" {$url} || echo 'PASS Security check unavailable')"
                echo "\$security_verdict"
                if [ "\${security_verdict%%[[:space:]]*}" = "BLOCK" ]; then
                    DEPLOYMENT_FAILURE_MESSAGE="\${security_verdict#BLOCK }"
                    false
                fi
            fi
            SCRIPT;
    }
}
