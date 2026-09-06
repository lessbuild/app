<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;
use App\Services\GitHubApp;

class CloneRepositoryScript extends BuildProvisioningScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Clone Repository';

    /**
     * Description of the script
     */
    public static string $description = 'Clone the repository on the server';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'cloned-repository';

    /**
     * The script to run
     */
    public function script(int $step, Build $build): string
    {
        $repository = $build->repository;
        $provider = $repository->provider;
        $host = $provider->repositoryHost();
        $username = $provider->repositoryCredentialUsername();
        $setupPath = escapeshellarg("/var/www/{$repository->website->deployment_slug}/setup");
        $credentialDirectory = escapeshellarg("/tmp/lessbuild-build-{$build->id}");
        $token = $provider->isGitHubApp()
            ? app(GitHubApp::class)->installationToken($provider->external_id)
            : $provider->token;
        $credentialPayload = escapeshellarg(base64_encode(
            "machine {$host}\nlogin {$username}\npassword {$token}\n",
        ));
        $repositoryUrl = escapeshellarg("https://{$repository->url}");
        $progress = $this->progress($step, $build);

        return <<<SCRIPT

            CREDENTIALS_DIR={$credentialDirectory}
            trap 'rm -rf -- "\$CREDENTIALS_DIR"; rm -f -- "\$0"' EXIT
            rm -rf -- {$setupPath}
            install -d -m 700 -- "\$CREDENTIALS_DIR"
            printf '%s' {$credentialPayload} | base64 --decode > "\$CREDENTIALS_DIR/.netrc"
            chmod 600 "\$CREDENTIALS_DIR/.netrc"
            HOME="\$CREDENTIALS_DIR" git clone -- {$repositoryUrl} {$setupPath}

            # Ping
            {$progress}

        SCRIPT;
    }
}
