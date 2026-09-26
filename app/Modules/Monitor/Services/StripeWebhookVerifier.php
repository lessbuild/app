<?php

namespace App\Modules\Monitor\Services;

use InvalidArgumentException;
use JsonException;

final class StripeWebhookVerifier
{
    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public function verify(string $payload, ?string $signature): array
    {
        $secret = config('monitor.beacon.billing.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '' || ! is_string($signature) || $signature === '') {
            throw new InvalidArgumentException('The Stripe webhook cannot be verified.');
        }

        $parts = $this->signatureParts($signature);
        $timestamp = $parts['timestamp'];
        $tolerance = max(1, (int) config('monitor.beacon.billing.stripe.webhook_tolerance_seconds', 300));

        if ($timestamp === null || abs(now('UTC')->timestamp - $timestamp) > $tolerance) {
            throw new InvalidArgumentException('The Stripe webhook signature is outside the allowed time window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $valid = false;

        foreach ($parts['signatures'] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $valid = true;
                break;
            }
        }

        if (! $valid) {
            throw new InvalidArgumentException('The Stripe webhook signature is invalid.');
        }

        $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            throw new InvalidArgumentException('The Stripe webhook payload is invalid.');
        }

        return $event;
    }

    /**
     * @return array{timestamp: int|null, signatures: list<string>}
     */
    private function signatureParts(string $signature): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return ['timestamp' => $timestamp, 'signatures' => $signatures];
    }
}
