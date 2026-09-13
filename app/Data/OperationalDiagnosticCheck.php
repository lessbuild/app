<?php

namespace App\Data;

use App\Enums\OperationalDiagnosticCategory;

final readonly class OperationalDiagnosticCheck
{
    /**
     * Carry one safe, categorized operational diagnostic without retaining
     * configuration values, exception text or remote response bodies.
     */
    public function __construct(
        public string $name,
        public OperationalDiagnosticCategory $category,
        public bool $passed,
        public string $detail,
    ) {}

    /**
     * Project the typed check into the legacy CLI, JSON and HTTP shape.
     *
     * @return array{name: string, passed: bool, detail: string}
     */
    public function toLegacyArray(): array
    {
        return [
            'name' => $this->name,
            'passed' => $this->passed,
            'detail' => $this->detail,
        ];
    }

    /**
     * Preserve the category when the safe check is stored in a server snapshot.
     *
     * @return array{name: string, category: string, passed: bool, detail: string}
     */
    public function toStoredArray(): array
    {
        return [
            ...$this->toLegacyArray(),
            'category' => $this->category->value,
        ];
    }
}
