<?php

namespace App\Data;

class ApplicationTemplateDefinition
{
    /**
     * Normalize one configured application preset for HTTP, project creation and preview consumers.
     *
     * @param  list<array<string, mixed>>  $processes  Process definitions copied into environments.
     * @param  list<array<string, mixed>>  $previewResources  Resource definitions used by supported previews.
     * @param  ServiceTemplateMetadata|null  $serviceTemplate  Published operational metadata, when curated.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $description,
        public readonly string $runtimeType,
        public readonly ?string $buildCommand,
        public readonly ?string $startCommand,
        public readonly ?int $containerPort,
        public readonly ?string $dockerfilePath,
        public readonly array $processes,
        public readonly array $previewResources,
        public readonly ?PreviewInitialization $initialization,
        public readonly ?ServiceTemplateMetadata $serviceTemplate,
    ) {}

    /**
     * Return the installed version to record, or null for legacy/unpublished presets.
     *
     * @return string|null A stable template version, when the preset is curated.
     */
    public function version(): ?string
    {
        return $this->serviceTemplate?->version;
    }
}
