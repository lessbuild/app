<?php

namespace App\Services;

use Symfony\Component\Yaml\Yaml;

class ApplicationConfigurationAuthoringGuide
{
    /**
     * Build the safe, parser-compatible guidance shown beside the version-2 authoring form.
     *
     * The example contains no credentials or commands. Binding IDs are deliberately illustrative
     * because the page's workspace catalog remains the authority for real IDs.
     *
     * @return array{document: string, bindings: string, fields: list<array{path: string, description: string}>} Authoring guidance for the web boundary.
     */
    public function for(): array
    {
        $document = Yaml::dump([
            'version' => 2,
            'environments' => [
                'staging' => [
                    'type' => 'staging',
                    'placement' => 'staging_site',
                    'runtime' => ['type' => 'php'],
                ],
            ],
        ], 6, 2);

        return [
            'document' => $document,
            'bindings' => json_encode([
                'placements' => ['staging_site' => 12],
                'secrets' => [],
                'repositories' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'fields' => [
                ['path' => 'environments.<name>.placement', 'description' => 'Maps to a website ID in the workspace bindings.'],
                ['path' => 'environments.<name>.runtime', 'description' => 'Requires a runtime type; Node/Python also require a start command and port, while Docker requires a relative Dockerfile.'],
                ['path' => 'processes / resources / variables', 'description' => 'Optional named children. Use secret_ref and bindings for existing secret values; never paste plaintext secrets.'],
                ['path' => 'deploy / remove', 'description' => 'Optional explicit workflow changes. Omitted objects are preserved; removals are reviewed and guarded.'],
            ],
        ];
    }
}
