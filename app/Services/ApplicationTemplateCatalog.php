<?php

namespace App\Services;

use App\Data\ApplicationTemplateDefinition;
use App\Data\PreviewInitialization;
use App\Data\ServiceTemplateMetadata;
use InvalidArgumentException;

class ApplicationTemplateCatalog
{
    /**
     * Return every configured application preset as an immutable definition for the creation form.
     *
     * @return array<string, ApplicationTemplateDefinition> Definitions keyed by persisted preset value.
     */
    public function all(): array
    {
        $definitions = [];

        foreach ($this->templates() as $key => $template) {
            if (is_array($template)) {
                $definitions[$key] = $this->definition($key, $template);
            }
        }

        return $definitions;
    }

    /**
     * Return the configured preset keys used by request validation.
     *
     * @return list<string> Persisted preset values accepted by the application form.
     */
    public function keys(): array
    {
        return array_keys($this->templates());
    }

    /**
     * Resolve one persisted preset or fail closed for an invalid application boundary.
     *
     * @param  string  $key  Persisted application preset value.
     * @return ApplicationTemplateDefinition The normalized configured definition.
     *
     * @throws InvalidArgumentException When the preset is not configured.
     */
    public function for(string $key): ApplicationTemplateDefinition
    {
        $template = $this->templates()[$key] ?? null;

        if (! is_array($template)) {
            throw new InvalidArgumentException('The application preset is not configured.');
        }

        return $this->definition($key, $template);
    }

    /**
     * @return array<string, mixed> The application preset configuration.
     */
    private function templates(): array
    {
        $templates = config('application-templates', []);

        return is_array($templates) ? $templates : [];
    }

    /**
     * @param  array<string, mixed>  $template  Raw trusted configuration entry.
     */
    private function definition(string $key, array $template): ApplicationTemplateDefinition
    {
        $initialization = $template['preview_initialization'] ?? null;
        $serviceTemplate = $template['service_template'] ?? null;

        return new ApplicationTemplateDefinition(
            key: $key,
            name: $this->string($template['name'] ?? null, $key),
            description: $this->string($template['description'] ?? null),
            runtimeType: $this->string($template['runtime_type'] ?? null, 'php'),
            buildCommand: $this->nullableString($template['build_command'] ?? null),
            startCommand: $this->nullableString($template['start_command'] ?? null),
            containerPort: is_int($template['container_port'] ?? null) ? $template['container_port'] : null,
            dockerfilePath: $this->nullableString($template['dockerfile_path'] ?? null),
            processes: is_array($template['processes'] ?? null) ? array_values($template['processes']) : [],
            previewResources: is_array($template['preview_resources'] ?? null) ? array_values($template['preview_resources']) : [],
            initialization: is_array($initialization)
                && is_string($initialization['command'] ?? null)
                && trim($initialization['command']) !== ''
                ? new PreviewInitialization($initialization['command'])
                : null,
            serviceTemplate: is_array($serviceTemplate) ? $this->serviceTemplate($serviceTemplate) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata  Raw curated template metadata.
     */
    private function serviceTemplate(array $metadata): ServiceTemplateMetadata
    {
        $version = $this->string($metadata['version'] ?? null);

        if ($version === '') {
            throw new InvalidArgumentException('A curated service template must declare a version.');
        }

        return new ServiceTemplateMetadata(
            version: $version,
            compatibility: is_array($metadata['compatibility'] ?? null) ? $metadata['compatibility'] : [],
            resources: is_array($metadata['resources'] ?? null) ? array_values($metadata['resources']) : [],
            persistentData: is_array($metadata['persistent_data'] ?? null) ? array_values($metadata['persistent_data']) : [],
            readinessChecks: is_array($metadata['readiness_checks'] ?? null) ? array_values($metadata['readiness_checks']) : [],
            resourceLimits: is_array($metadata['resource_limits'] ?? null) ? $metadata['resource_limits'] : [],
            backupRestore: is_array($metadata['backup_restore'] ?? null) ? $metadata['backup_restore'] : [],
            upgrade: is_array($metadata['upgrade'] ?? null) ? $metadata['upgrade'] : [],
            failureRecovery: is_array($metadata['failure_recovery'] ?? null) ? $metadata['failure_recovery'] : [],
            deletion: is_array($metadata['deletion'] ?? null) ? $metadata['deletion'] : [],
        );
    }

    private function string(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
