<?php

namespace App\Modules\Analytics\Services\Billing;

use InvalidArgumentException;
use JsonException;

/** Verify a raw Analytics Stripe webhook before any event or entitlement is stored. */
final class AnalyticsStripeWebhookVerifier
{
    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException|JsonException
     */
    public function verify(string $payload, ?string $signature): array
    {
        $secret = config('analytics.billing.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '' || ! is_string($signature) || $signature === '') {
            throw new InvalidArgumentException('The Analytics billing webhook cannot be verified.');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[a-f0-9]{64}$/i', $value)) {
                $signatures[] = strtolower($value);
            }
        }

        $tolerance = max(1, (int) config('analytics.billing.stripe.webhook_tolerance_seconds', 300));
        if ($timestamp === null || abs(now('UTC')->timestamp - $timestamp) > $tolerance || $signatures === []) {
            throw new InvalidArgumentException('The Analytics billing webhook signature is invalid.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        if (! collect($signatures)->contains(fn (string $candidate): bool => hash_equals($expected, $candidate))) {
            throw new InvalidArgumentException('The Analytics billing webhook signature is invalid.');
        }

        $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($event)
            || ! is_string($event['id'] ?? null)
            || ! preg_match('/^evt_[A-Za-z0-9]+$/', $event['id'])
            || ! is_string($event['type'] ?? null)
            || ! is_array($event['data']['object'] ?? null)) {
            throw new InvalidArgumentException('The Analytics billing webhook payload is invalid.');
        }

        return $event;
    }
}
