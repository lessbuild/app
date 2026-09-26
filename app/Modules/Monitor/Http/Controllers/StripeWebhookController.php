<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Services\ProcessStripeBillingEvent;
use App\Modules\Monitor\Services\StripeWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class StripeWebhookController extends Controller
{
    public function store(Request $request, StripeWebhookVerifier $verifier, ProcessStripeBillingEvent $processor): JsonResponse
    {
        if (blank(config('monitor.beacon.billing.stripe.webhook_secret'))) {
            return response()->json(['message' => 'Stripe webhooks are not configured.'], 503);
        }

        try {
            $event = $verifier->verify($request->getContent(), $request->header('Stripe-Signature'));
        } catch (Throwable) {
            return response()->json(['message' => 'Invalid Stripe webhook.'], 400);
        }

        try {
            $processed = $processor->handle($event);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Stripe webhook processing failed.'], 500);
        }

        return response()->json(['received' => true, 'duplicate' => ! $processed]);
    }
}
