<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Actions\HandleBillingWebhook;
use App\Domain\Billing\Contracts\PaymentProvider;
use App\Domain\Billing\Exceptions\InvalidWebhook;
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
