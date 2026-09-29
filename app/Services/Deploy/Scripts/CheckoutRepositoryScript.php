<?php

declare(strict_types=1);

namespace App\Services\Deploy\Scripts;

use App\Models\Build;
use App\Services\Infrastructure\ProvisioningCallbackUrl;
use App\Support\GitRef;
use InvalidArgumentException;

class CheckoutRepositoryScript extends BuildProvisioningScript
{
    public const TITLE = 'Checkout Repository';

    public const DESCRIPTION = 'Checkout the repository on the server';

    public const IDENTIFIER = 'checked-repository';

    /**
     * Render the stage that checks out the build's commit (which must be on the branch), or the branch, tag or commit
     * someone asked for, and reports the revision and commit message.
     *
     * @param  int  $step
     * @param  Build  $build
     * @return string
     */
    public function script(int $step, Build $build): string
    {
        $repository = $build->repository;
        $setupPath = escapeshellarg($build->deploymentPath('setup'));
        $branch = escapeshellarg($repository->branch);
        $remoteBranch = escapeshellarg("origin/{$repository->branch}");
        $revision = $build->revision;
        $revisionCallback = escapeshellarg(ProvisioningCallbackUrl::buildRevision($build));
        $progress = $this->progress($step, $build);

        if ($build->git_ref !== null) {
            if (GitRef::normalize($build->git_ref) !== $build->git_ref) {
                throw new InvalidArgumentException('The requested ref is invalid.');
            }
            // A branch on the remote first, then a tag, then a commit; whichever it is, check out that exact commit.
            $ref = escapeshellarg($build->git_ref);
            $remoteRef = escapeshellarg("origin/{$build->git_ref}");
            $tagRef = escapeshellarg("refs/tags/{$build->git_ref}");
            $checkout = <<<SCRIPT
            git -C {$setupPath} fetch --tags --force origin >/dev/null 2>&1 || true
            REQUESTED_COMMIT="$(git -C {$setupPath} rev-parse --verify --quiet {$remoteRef}^{commit} || git -C {$setupPath} rev-parse --verify --quiet {$tagRef}^{commit} || git -C {$setupPath} rev-parse --verify --quiet {$ref}^{commit} || true)"
            if [ -z "\$REQUESTED_COMMIT" ]; then echo "No branch, tag or commit called {$build->git_ref} in the repository." >&2; exit 1; fi
            git -C {$setupPath} checkout --detach --force "\$REQUESTED_COMMIT"
            SCRIPT;
        } elseif ($revision) {
            if (! preg_match('/\A[0-9a-f]{40,64}\z/D', $revision)) {
                throw new InvalidArgumentException('The build revision is invalid.');
            }

            $revision = escapeshellarg($revision);
            $checkout = <<<SCRIPT
            git -C {$setupPath} rev-parse --verify {$revision}^{commit} >/dev/null
            git -C {$setupPath} merge-base --is-ancestor {$revision} {$remoteBranch}
            git -C {$setupPath} checkout --detach --force {$revision}
            SCRIPT;
        } else {
            $checkout = "git -C {$setupPath} checkout --force {$branch}";
        }

        return <<<SCRIPT

            {$checkout}

            DEPLOYED_REVISION="$(git -C {$setupPath} rev-parse HEAD)"
            DEPLOYED_MESSAGE="$(git -C {$setupPath} log -1 --format=%B)"
            DEPLOYED_MESSAGE="\${DEPLOYED_MESSAGE:0:500}"
            curl --fail --silent --show-error --retry 2 --user-agent "deployer" \
                --data-urlencode "revision=\$DEPLOYED_REVISION" \
                --data-urlencode "commit_message=\$DEPLOYED_MESSAGE" \
                {$revisionCallback}

            # Ping
            {$progress}

        SCRIPT;
    }
}
