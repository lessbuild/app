<?php

namespace App\Core\Contracts;

use App\Core\Data\Restoration\NativeRestorationReceipt;
use App\Core\Data\Restoration\NativeRestorationSnapshot;
use App\Core\Data\Restoration\ResourceRestorationAttempt;
use App\Core\Data\Restoration\ResourceRestorationTarget;
use App\Core\Models\PlatformUser;
use Closure;

interface ProductResourceRestorationProvider
{
    public function product(): string;

    /** @return list<string> */
    public function resourceTypes(): array;

    public function inspect(PlatformUser $actor, ResourceRestorationTarget $target, ?string $receiptRequestId = null): NativeRestorationSnapshot;

    public function apply(ResourceRestorationAttempt $attempt): NativeRestorationReceipt;

    /** Reauthorize and invoke $commit($receipt) while holding native lifecycle locks. */
    public function withCurrentReceipt(ResourceRestorationAttempt $attempt, Closure $commit): void;
}
