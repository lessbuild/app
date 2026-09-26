<?php

namespace App\Modules\Analytics\Services\Billing;

/** Resolve only explicitly configured Analytics prices and immutable plan snapshots. */
final class AnalyticsBillingCatalog
{
    /**
     * @return array<string, array{
     *     key:string,
     *     name:string,
     *     description:?string,
     *     price_id:string,
     *     amount:int,
     *     currency:string,
     *     interval:string,
     *     interval_count:int,
     *     snapshot:array{name:string,description?:string,entitlements:list<string>,limits:array<string,int|null>}
     * }>
     */
    public function plans(): array
    {
        $configured = config('analytics.billing.plans', []);
        if (! is_array($configured)) {
            return [];
        }

        $plans = [];
        $priceCounts = [];

        foreach ($configured as $key => $source) {
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_-]{0,99}$/', $key) || ! is_array($source)) {
                continue;
            }

            $plan = $this->normalize($key, $source);
            if ($plan === null) {
                continue;
            }

            $priceCounts[$plan['price_id']] = ($priceCounts[$plan['price_id']] ?? 0) + 1;
            $plans[$key] = $plan;
        }

        foreach ($plans as $key => $plan) {
            if ($priceCounts[$plan['price_id']] !== 1) {
                unset($plans[$key]);
            }
        }

        return $plans;
    }

    /** @return array<string, mixed>|null */
    public function forKey(string $planKey): ?array
    {
        return $this->plans()[$planKey] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function forPrice(string $priceId): ?array
    {
        foreach ($this->plans() as $plan) {
            if ($plan['price_id'] === $priceId) {
                return $plan;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $plan
     * @return array{price_id:string,amount:int,currency:string,interval:string,interval_count:int,billing_scheme:string,usage_type:string,transform_quantity:null}
     */
    public function priceTerms(array $plan): array
    {
        if (! is_string($plan['price_id'] ?? null)
            || ! preg_match('/^price_[A-Za-z0-9]+$/', $plan['price_id'])
            || ! is_int($plan['amount'] ?? null)
            || $plan['amount'] < 1
            || ! is_string($plan['currency'] ?? null)
            || ! in_array(strtolower($plan['currency']), ['usd', 'eur', 'gbp', 'cad', 'aud', 'nzd'], true)
            || ! is_string($plan['interval'] ?? null)
            || ! in_array($plan['interval'], ['day', 'week', 'month', 'year'], true)
            || ! is_int($plan['interval_count'] ?? null)
            || $plan['interval_count'] < 1
            || $plan['interval_count'] > 100) {
            throw new AnalyticsBillingException('The approved Analytics price terms are invalid.');
        }

        return [
            'price_id' => $plan['price_id'],
            'amount' => $plan['amount'],
            'currency' => strtolower($plan['currency']),
            'interval' => $plan['interval'],
            'interval_count' => $plan['interval_count'],
            'billing_scheme' => 'per_unit',
            'usage_type' => 'licensed',
            'transform_quantity' => null,
        ];
    }

    /** @param array<string, mixed> $plan */
    public function termsHash(array $plan): string
    {
        return $this->storedTermsHash($this->priceTerms($plan));
    }

    /** @param array<string, mixed> $terms */
    public function storedTermsHash(array $terms): string
    {
        $canonical = $this->canonicalPriceTerms($terms);
        if ($canonical === null) {
            throw new AnalyticsBillingException('The stored Analytics price terms are invalid.');
        }

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $source
     * @return array<string, mixed>|null
     */
    private function normalize(string $key, array $source): ?array
    {
        $name = $source['name'] ?? null;
        $priceId = $source['price_id'] ?? null;
        $amount = $source['amount'] ?? null;
        $currency = $source['currency'] ?? null;
        $interval = $source['interval'] ?? null;
        $intervalCount = $source['interval_count'] ?? 1;
        $description = $source['description'] ?? null;
        $snapshot = $source['snapshot'] ?? null;

        if (! is_string($name) || trim($name) === ''
            || ! is_string($priceId) || ! preg_match('/^price_[A-Za-z0-9]+$/', $priceId)
            || ! is_int($amount) || $amount <= 0
            || ! is_string($currency) || ! in_array(strtolower($currency), ['usd', 'eur', 'gbp', 'cad', 'aud', 'nzd'], true)
            || ! is_string($interval) || ! in_array($interval, ['day', 'week', 'month', 'year'], true)
            || ! is_int($intervalCount) || $intervalCount < 1 || $intervalCount > 100
            || ($description !== null && ! is_string($description))
            || ! is_array($snapshot)) {
            return null;
        }

        $snapshotName = $snapshot['name'] ?? null;
        $entitlements = $snapshot['entitlements'] ?? null;
        $limits = $snapshot['limits'] ?? null;
        $snapshotDescription = $snapshot['description'] ?? $description;

        if (! is_string($snapshotName) || trim($snapshotName) === '' || $snapshotName !== $name
            || ! is_array($entitlements) || ! array_is_list($entitlements)
            || ! is_array($limits)
            || ($snapshotDescription !== null && ! is_string($snapshotDescription))) {
            return null;
        }

        foreach ($entitlements as $entitlement) {
            if (! is_string($entitlement) || trim($entitlement) === '' || $entitlement === '*') {
                return null;
            }
        }

        if ($entitlements === [] || count(array_unique($entitlements)) !== count($entitlements)) {
            return null;
        }

        $requiredLimits = [
            'sites',
            'members',
            'events_per_month',
            'retention_days',
            'aggregate_retention_months',
            'export_retention_hours',
        ];

        foreach ($requiredLimits as $requiredLimit) {
            if (! array_key_exists($requiredLimit, $limits)) {
                return null;
            }
        }

        foreach ($limits as $limit => $value) {
            if (! is_string($limit) || trim($limit) === ''
                || (! is_int($value) && $value !== null)
                || (is_int($value) && $value < 0)) {
                return null;
            }
        }

        $normalizedSnapshot = [
            'name' => trim($snapshotName),
            'entitlements' => array_values($entitlements),
            'limits' => $limits,
        ];
        if (is_string($snapshotDescription) && trim($snapshotDescription) !== '') {
            $normalizedSnapshot['description'] = trim($snapshotDescription);
        }

        return [
            'key' => $key,
            'name' => trim($name),
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            'price_id' => $priceId,
            'amount' => $amount,
            'currency' => strtolower($currency),
            'interval' => $interval,
            'interval_count' => $intervalCount,
            'snapshot' => $normalizedSnapshot,
        ];
    }

    /** @param array<string, mixed> $terms
     * @return array{price_id:string,amount:int,currency:string,interval:string,interval_count:int,billing_scheme:string,usage_type:string,transform_quantity:null}|null
     */
    private function canonicalPriceTerms(array $terms): ?array
    {
        $expectedKeys = [
            'price_id',
            'amount',
            'currency',
            'interval',
            'interval_count',
            'billing_scheme',
            'usage_type',
            'transform_quantity',
        ];
        $actualKeys = array_keys($terms);
        sort($expectedKeys);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys) {
            return null;
        }

        if (! is_string($terms['price_id'] ?? null)
            || ! preg_match('/^price_[A-Za-z0-9]+$/', $terms['price_id'])
            || ! is_int($terms['amount'] ?? null)
            || $terms['amount'] < 1
            || ! is_string($terms['currency'] ?? null)
            || ! in_array(strtolower($terms['currency']), ['usd', 'eur', 'gbp', 'cad', 'aud', 'nzd'], true)
            || ! is_string($terms['interval'] ?? null)
            || ! in_array($terms['interval'], ['day', 'week', 'month', 'year'], true)
            || ! is_int($terms['interval_count'] ?? null)
            || $terms['interval_count'] < 1
            || $terms['interval_count'] > 100
            || ($terms['billing_scheme'] ?? null) !== 'per_unit'
            || ($terms['usage_type'] ?? null) !== 'licensed'
            || ($terms['transform_quantity'] ?? null) !== null) {
            return null;
        }

        return [
            'price_id' => $terms['price_id'],
            'amount' => $terms['amount'],
            'currency' => strtolower($terms['currency']),
            'interval' => $terms['interval'],
            'interval_count' => $terms['interval_count'],
            'billing_scheme' => 'per_unit',
            'usage_type' => 'licensed',
            'transform_quantity' => null,
        ];
    }
}
