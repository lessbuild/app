<?php

namespace App\Http\Controllers;

use App\Actions\Repository\HandleRepositoryWebhookAction;
use App\Data\RepositoryWebhookResult;
use App\Exceptions\InvalidRepositoryWebhook;
use App\Models\Repository;
use App\Services\PreviewDeploymentLifecycle;
use App\Services\RepositoryWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RepositoryWebhookController extends Controller
{
    /**
     * Verify a repository webhook, route preview events, and queue matching push deployments.
     *
     * @return JsonResponse The processing status: 202 for accepted work, 409 when unavailable, or the verification error.
     */
    public function __invoke(
        Request $request,
        Repository $repository,
        RepositoryWebhookVerifier $verifier,
        HandleRepositoryWebhookAction $handle,
        PreviewDeploymentLifecycle $previews,
    ): JsonResponse {
        try {
            $webhook = $verifier->verify($repository, $request);
        } catch (InvalidRepositoryWebhook $exception) {
            $status = in_array($exception->getCode(), [404, 413, 422], true)
                ? $exception->getCode()
                : 401;

            return response()->json([
                'status' => match ($status) {
                    404 => 'not_found',
                    413 => 'payload_too_large',
                    422 => 'invalid_payload',
                    default => 'unauthorized',
                },
            ], $status);
        }

        if ($webhook->isPreviewEvent()) {
            $status = $previews->handle($repository, $webhook);

            return response()->json(['status' => $status], in_array($status, ['provisioning', 'deploying'], true) ? 202 : 200);
        }

        if (! $webhook->isPush) {
            return response()->json(['status' => 'event_ignored']);
        }

        if (! $webhook->matchesBranch) {
            return response()->json(['status' => 'branch_ignored']);
        }

        $result = $handle->handle($repository, $webhook);
        $status = match ($result->status) {
            RepositoryWebhookResult::QUEUED, RepositoryWebhookResult::PENDING => 202,
            RepositoryWebhookResult::UNAVAILABLE => 409,
            default => 200,
        };

        return response()->json(['status' => $result->status], $status);
    }
}
