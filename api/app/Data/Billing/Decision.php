<?php

declare(strict_types=1);

namespace App\Data\Billing;

final readonly class Decision
{
    /**
     * Create a new Decision instance.
     *
     * The answer to "may the account have one more of these?".
     *
     * @param  bool  $allowed  Whether it may.
     * @param  ?int  $limit  The plan's limit, or null when there's none.
     * @param  ?string  $reason  Why not, ready to show, when it may not.
     */
    public function __construct(
        public bool $allowed,
        public ?int $limit,
        public ?string $reason = null,
    ) {}
}
