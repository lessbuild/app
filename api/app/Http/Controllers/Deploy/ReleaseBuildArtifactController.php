<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\ReleaseBuildArtifact;
use App\Models\Build;
use Illuminate\Http\Response;

/** A build server reports that a build's release is uploaded (a signed `/builds/{build}/deployment/callback/artifact` URL). */
final class ReleaseBuildArtifactController
{
    /**
     * Start the release part on the website's server. The URL's signature, checked by middleware, proves it came
     * from the build's script.
     *
     * @param  string  $build
     * @param  ReleaseBuildArtifact  $release
     * @return Response
     */
    public function __invoke(string $build, ReleaseBuildArtifact $release): Response
    {
        $release->handle(Build::query()->findOrFail((int) $build));

        return response()->noContent();
    }
}
