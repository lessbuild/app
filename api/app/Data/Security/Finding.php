<?php

declare(strict_types=1);

namespace App\Data\Security;

final readonly class Finding
{
    /**
     * Create a new Finding instance.
     *
     * A problem a scanner found, before it's stored.
     *
     * @param  string  $key  identifies the problem within its scope, e.g. "composer:laravel/framework:GHSA-xxxx"
     * @param  string  $severity  one of SecurityFinding::SEVERITIES' keys
     * @param  string  $title  one line saying what's wrong
     * @param  string|null  $detail  more about it
     * @param  string|null  $subject  what it's about, e.g. a website or package
     * @param  string|null  $url  where to read more
     * @param  string|null  $fix  how to fix it
     * @param  array<string, mixed>  $data  anything else to keep, for the page or later fixes
     */
    public function __construct(
        public string $key,
        public string $severity,
        public string $title,
        public ?string $detail = null,
        public ?string $subject = null,
        public ?string $url = null,
        public ?string $fix = null,
        public array $data = [],
    ) {}
}
