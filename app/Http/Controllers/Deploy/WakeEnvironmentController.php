<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Jobs\Deploy\ApplyEnvironmentRuntime;
use App\Models\Environment;
use Illuminate\Http\Response;

/** POST /environments/{environment}/wake: a hibernating website's server reports its first request. */
final class WakeEnvironmentController
{
    /**
     * Start waking the environment when it's hibernating. The URL is signed per environment; calling it again does
     * nothing more.
     *
     * @param  string  $environment
     * @return Response
     */
    public function __invoke(string $environment): Response
    {
        $found = Environment::query()->find($environment);
        if ($found !== null && $found->hibernated_at !== null) {
            ApplyEnvironmentRuntime::dispatch($found->id, false);
        }

        return response()->noContent(202);
    }
}
