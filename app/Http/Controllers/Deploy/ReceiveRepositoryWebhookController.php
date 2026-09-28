<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Repository;
use App\Services\Deploy\RepositoryPushReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A Git host's push webhook (`POST /api/repositories/{repository}/webhook`, Deployer's public contract and responses). */
final class ReceiveRepositoryWebhookController
{
    /**
     * Hands a push webhook to its repository, answering with Deployer's status codes; unknown repositories get a 404.
     *
     * @param  Request  $request
     * @param  string  $repository
     * @param  RepositoryPushReceiver  $receive
     * @return JsonResponse
     */
    public function __invoke(Request $request, string $repository, RepositoryPushReceiver $receive): JsonResponse
    {
        $target = Repository::query()->with(['provider', 'website.server'])->find((int) $repository);
        if ($target === null) {
            return response()->json(['status' => 'not_found'], 404);
        }
        [$status, $code] = $receive->handle($target, $request);

        return response()->json(['status' => $status], $code);
    }
}
