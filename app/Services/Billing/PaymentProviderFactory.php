<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\PaymentProvider;
use Illuminate\Contracts\Config\Repository;
use Stripe\StripeClient;

final class PaymentProviderFactory
{
    /** Stripe when STRIPE_SECRET is set; otherwise a provider that only allows free tiers. */
    public static function make(Repository $config): PaymentProvider
    {
        $secret = $config->get('services.stripe.secret');
        $webhookSecret = $config->get('services.stripe.webhook_secret');

        return is_string($secret) && $secret !== ''
            ? new StripePaymentProvider(new StripeClient($secret), is_string($webhookSecret) ? $webhookSecret : null)
            : new UnavailablePaymentProvider;
    }
}
