<?php

declare(strict_types=1);

namespace App\Data\Deploy;

final readonly class ProductionIssue
{
    /**
     * Create a new ProductionIssue instance.
     *
     * Something about an environment that isn't right for production, and the fix Buildpusher can make, if any.
     *
     * @param  string  $key  identifies the issue within the environment, e.g. "app-debug"
     * @param  string  $severity  critical, warning or notice
     * @param  string  $title  one line saying what's wrong
     * @param  string  $detail  why it matters
     * @param  string|null  $fixLabel  the button that fixes it, when Buildpusher can
     * @param  array<string, string>  $variables  the variables the fix sets
     * @param  array{name: string, type: string, command: string}|null  $process  the worker the fix adds
     * @param  string|null  $url  where to fix it by hand, when Buildpusher can't
     */
    public function __construct(
        public string $key,
        public string $severity,
        public string $title,
        public string $detail,
        public ?string $fixLabel = null,
        public array $variables = [],
        public ?array $process = null,
        public ?string $url = null,
    ) {}

    /**
     * Whether Buildpusher can fix it in a click.
     *
     * @return bool
     */
    public function fixable(): bool
    {
        return $this->fixLabel !== null && ($this->variables !== [] || $this->process !== null);
    }

    /**
     * Get the issue for the environment page.
     *
     * @return array{key: string, severity: string, title: string, detail: string, fixLabel: string|null, fixable: bool, url: string|null}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'severity' => $this->severity, 'title' => $this->title, 'detail' => $this->detail, 'fixLabel' => $this->fixLabel, 'fixable' => $this->fixable(), 'url' => $this->url];
    }
}
