<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Models\Environment;

final readonly class EnvironmentSummary
{
    /**
     * Create a new EnvironmentSummary instance.
     *
     * An environment as lists show it.
     *
     * @param  string  $id
     * @param  string  $name
     * @param  string  $kind  production, staging, development or preview
     * @param  string  $kindLabel
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $kind,
        public string $kindLabel,
    ) {}

    /**
     * Describe an environment.
     *
     * @param  Environment  $environment
     * @return self
     */
    public static function from(Environment $environment): self
    {
        return new self($environment->id, $environment->name, $environment->kind->value, $environment->kind->label());
    }
}
