<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\Billing\HandleBillingWebhook;
use App\Contracts\PaymentProvider;
use App\Exceptions\InvalidWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class StripeWebhookController
{
    public function __invoke(Request $request, PaymentProvider $provider, HandleBillingWebhook $handle): Response
    {
        try {
            $event = $provider->verifyWebhook($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (InvalidWebhook) {
            return response('Invalid signature', 400);
        }
        $handle->handle($event);

        return response('OK');
    }
}
