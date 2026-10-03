<?php

declare(strict_types=1);

namespace App\Data\Admin;

/** One system health check's outcome. */
final readonly class HealthCheck
{
    /**
     * Create a new HealthCheck instance.
     *
     * A named check that passed or failed, with what was found.
     *
     * @param  string  $name  What was checked, e.g. "Database connection".
     * @param  string  $category  runtime, storage, connectivity or processes
     * @param  bool  $passed  Whether it's healthy.
     * @param  string  $detail  What was found, safe to show and export.
     */
    public function __construct(public string $name, public string $category, public bool $passed, public string $detail) {}

    /**
     * Get the check as the JSON report shows it.
     *
     * @return array{name: string, category: string, passed: bool, detail: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'category' => $this->category, 'passed' => $this->passed, 'detail' => $this->detail];
    }
}
