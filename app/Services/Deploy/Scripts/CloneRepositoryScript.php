<?php

declare(strict_types=1);

namespace App\Services\Deploy\Scripts;

use App\Models\Build;
use RuntimeException;

class CloneRepositoryScript extends BuildProvisioningScript
{
    public const TITLE = 'Clone Repository';

    public const DESCRIPTION = 'Clone the repository on the server';

    public const IDENTIFIER = 'cloned-repository';

    /**
     * The script to run
     *
     * @param  int  $step
     * @param  Build  $build
     * @return string
     */
    public function script(int $step, Build $build): string
    {
        $repository = $build->repository;
        $provider = $repository->provider ?? throw new RuntimeException('The repository has no Git provider.');
        $host = $provider->type->repositoryHost();
        $username = $provider->type->repositoryCredentialUsername();
        $setup = $build->deploymentPath('setup');
        $setupPath = escapeshellarg($setup);
        $setupParent = escapeshellarg(dirname($setup));
        $credentialDirectory = escapeshellarg("/tmp/lessbuild-build-{$build->id}");
        $token = $provider->isGitHubApp() ? app(\App\Services\Deploy\GitHubApp::class)->installationToken((string) $provider->external_id) : $provider->token;
        $credentialPayload = escapeshellarg(base64_encode(
            "machine {$host}\nlogin {$username}\npassword {$token}\n",
        ));
        $repositoryUrl = escapeshellarg("https://{$repository->url}");
        $progress = $this->progress($step, $build);

        return <<<SCRIPT

            CREDENTIALS_DIR={$credentialDirectory}
            trap 'rm -rf -- "\$CREDENTIALS_DIR"; rm -f -- "\$0"' EXIT
            rm -rf -- {$setupPath}
            install -d -m 755 -- {$setupParent}
            install -d -m 700 -- "\$CREDENTIALS_DIR"
            printf '%s' {$credentialPayload} | base64 --decode > "\$CREDENTIALS_DIR/.netrc"
            chmod 600 "\$CREDENTIALS_DIR/.netrc"
            HOME="\$CREDENTIALS_DIR" git clone -- {$repositoryUrl} {$setupPath}

            # Ping
            {$progress}

        SCRIPT;
    }
}
