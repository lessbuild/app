<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Services\Billing\AnalyticsStripeWebhookVerifier;
use App\Modules\Analytics\Services\Billing\ProcessAnalyticsBillingWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class AnalyticsStripeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        AnalyticsStripeWebhookVerifier $verifier,
        ProcessAnalyticsBillingWebhook $processor,
    ): JsonResponse {
        if (! config('analytics.billing.webhooks_enabled', false)) {
            return response()->json(['message' => 'Analytics billing webhooks are unavailable.'], 503);
        }

        try {
            $event = $verifier->verify($request->getContent(), $request->header('Stripe-Signature'));
        } catch (Throwable) {
            return response()->json(['message' => 'Invalid Analytics billing webhook.'], 400);
        }

        try {
            $processed = $processor->handle($event);
        } catch (Throwable $exception) {
            report($exception);

            // A non-2xx response lets Stripe retry unresolved provider or mapping state.
            return response()->json(['message' => 'Analytics billing reconciliation failed.'], 500);
        }

        return response()->json(['received' => true, 'duplicate' => ! $processed]);
    }
}
