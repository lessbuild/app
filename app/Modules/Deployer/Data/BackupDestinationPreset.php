<?php

namespace App\Modules\Deployer\Data;

final readonly class BackupDestinationPreset
{
    /**
     * Describe one supported S3-compatible setup path for the backup form.
     *
     * @param  string|null  $documentationUrl  Optional provider guidance URL shown beside the setup instructions.
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $endpointHint,
        public string $regionHint,
        public ?string $documentationUrl = null,
    ) {}
}
