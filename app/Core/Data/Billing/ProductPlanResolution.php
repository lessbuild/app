<?php

namespace App\Core\Data\Billing;

use App\Core\Enums\ProductKey;

final readonly class ProductPlanResolution
{
    /**
     * @param  list<string>  $entitlements
     * @param  array<string, int|null>  $limits
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public ProductKey $product,
        public ?string $workspaceId,
        public bool $available,
        public ?string $planKey = null,
        public ?string $planName = null,
        public ?string $subscriptionStatus = null,
        public array $entitlements = [],
        public array $limits = [],
        public array $snapshot = [],
        public ?string $unavailableReason = null,
    ) {}

    public static function unavailable(
        ProductKey $product,
        ?string $workspaceId,
        string $reason,
        ?string $subscriptionStatus = null,
    ): self {
        return new self(
            product: $product,
            workspaceId: $workspaceId,
            available: false,
            subscriptionStatus: $subscriptionStatus,
            unavailableReason: $reason,
        );
    }

    public function allows(string $entitlement): bool
    {
        return $this->available
            && (in_array('*', $this->entitlements, true) || in_array($entitlement, $this->entitlements, true));
    }

    public function hasLimit(string $resource): bool
    {
        return $this->available && array_key_exists($resource, $this->limits);
    }

    /** A null value represents an explicitly unlimited resource. */
    public function limit(string $resource): ?int
    {
        return $this->hasLimit($resource) ? $this->limits[$resource] : null;
    }
}
