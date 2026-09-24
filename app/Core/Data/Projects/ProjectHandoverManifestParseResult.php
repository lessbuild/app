<?php

namespace App\Core\Data\Projects;

final readonly class ProjectHandoverManifestParseResult
{
    /**
     * @param  array<string, mixed>|null  $manifest
     * @param  'valid'|'invalid'|'unsupported_schema'  $status
     */
    public function __construct(
        public ?array $manifest,
        public string $status,
    ) {}
}
