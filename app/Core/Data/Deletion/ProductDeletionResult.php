<?php

namespace App\Core\Data\Deletion;

final readonly class ProductDeletionResult
{
    /** @param list<string> $retained Explicit records retained after cleanup. */
    public function __construct(public string $status, public ?string $reasonCode = null, public array $retained = []) {}
}
