<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\HandleRepositoryWebhook;
use App\Exceptions\InvalidRepositoryWebhook;
use App\Models\Repository;
use App\Services\Deploy\RepositoryWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A Git host's push webhook (`POST /api/repositories/{repository}/webhook`, Deployer's public contract and responses). */
final class ReceiveRepositoryWebhookController
{
    public function __invoke(Request $request, string $repository, RepositoryWebhookVerifier $verifier, HandleRepositoryWebhook $handle): JsonResponse
    {
        $target = Repository::query()->with(['provider', 'website.server'])->find((int) $repository);
        if ($target === null) {
            return response()->json(['status' => 'not_found'], 404);
        }
        try {
            $webhook = $verifier->verify($target, $request);
        } catch (InvalidRepositoryWebhook $exception) {
            $status = in_array($exception->getCode(), [404, 413, 422], true) ? $exception->getCode() : 401;

            return response()->json(['status' => match ($status) {
                404 => 'not_found', 413 => 'payload_too_large', 422 => 'invalid_payload', default => 'unauthorized'
            }], $status);
        }
        if (! $webhook->isPush) {
            return response()->json(['status' => 'event_ignored']);
        }
        if (! $webhook->matchesBranch) {
            return response()->json(['status' => 'branch_ignored']);
        }
        $status = $handle->handle($target, $webhook);

        return response()->json(['status' => $status], match ($status) {
            'queued', 'pending', 'awaiting_approval' => 202, 'unavailable' => 409, default => 200
        });
    }
}
