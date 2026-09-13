<?php

namespace App\Data;

use App\Enums\OperationalDiagnosticCategory;

final readonly class OperationalDiagnosticReport
{
    /**
     * Carry the ordered, categorized result of one operational diagnostic run.
     *
     * @param  list<OperationalDiagnosticCheck>  $checks  Safe checks in the
     *                                                    established execution order.
     */
    public function __construct(public array $checks) {}

    /** Return whether every check in this report passed. */
    public function passed(): bool
    {
        return $this->passedCount() === count($this->checks);
    }

    /** Return the number of checks that passed. */
    public function passedCount(): int
    {
        return count(array_filter($this->checks, fn (OperationalDiagnosticCheck $check): bool => $check->passed));
    }

    /**
     * Return checks belonging to one operational concern.
     *
     * @return list<OperationalDiagnosticCheck>
     */
    public function forCategory(OperationalDiagnosticCategory $category): array
    {
        return array_values(array_filter(
            $this->checks,
            fn (OperationalDiagnosticCheck $check): bool => $check->category === $category,
        ));
    }

    /**
     * Preserve the established untyped result consumed by current adapters.
     *
     * @return list<array{name: string, passed: bool, detail: string}>
     */
    public function toLegacyChecks(): array
    {
        return array_map(
            fn (OperationalDiagnosticCheck $check): array => $check->toLegacyArray(),
            $this->checks,
        );
    }
}
