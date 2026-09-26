<?php

namespace App\Core\Data\Deletion;

final readonly class ProductDeletionPreview
{
    /** @param list<string> $blockers Safe reason codes. @param list<string> $retained Human-readable retention descriptions. */
    public function __construct(public array $blockers = [], public array $retained = [], public array $counts = []) {}
}
