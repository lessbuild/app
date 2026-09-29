<?php

declare(strict_types=1);

namespace App\Data\Billing;

/** How much of one plan limit an account is using. */
final readonly class LimitUsage
{
    /**
     * Create a new LimitUsage instance.
     *
     * @param  string  $key  The entitlement, e.g. infrastructure.servers.max.
     * @param  string  $service  The service whose plan sets it (or "account" for members).
     * @param  string  $label  What's counted, e.g. "servers".
     * @param  int  $used  How many are in use (this month, for monthly limits).
     * @param  int|null  $limit  The plan's limit, or null for unlimited.
     * @param  bool  $monthly  Whether it resets each month.
     */
    public function __construct(
        public string $key,
        public string $service,
        public string $label,
        public int $used,
        public ?int $limit,
        public bool $monthly = false,
    ) {}

    /**
     * Get how much of the limit is used, as a whole percentage (0 when unlimited).
     *
     * @return int
     */
    public function percent(): int
    {
        return $this->limit === null || $this->limit <= 0 ? 0 : (int) min(100, floor($this->used * 100 / $this->limit));
    }

    /**
     * Determine whether it's worth warning about: 80% or more used.
     *
     * @return bool
     */
    public function nearLimit(): bool
    {
        return $this->limit !== null && $this->percent() >= 80;
    }
}
