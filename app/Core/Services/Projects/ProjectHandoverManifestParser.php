<?php

namespace App\Core\Services\Projects;

use App\Core\Data\Projects\ProjectHandoverManifestParseResult;
use App\Core\Enums\ProductKey;
use App\Core\Enums\ProjectConnectionCapability;
use Illuminate\Support\Str;
use JsonException;

/** Parses the bounded, versioned, secret-free handover interchange format. */
final class ProjectHandoverManifestParser
{
    private const MAX_ENVIRONMENTS = 200;

    private const MAX_RESOURCES = 1000;

    private const MAX_CONNECTIONS = 1000;

    /** @return ProjectHandoverManifestParseResult */
    public function parse(string $contents): ProjectHandoverManifestParseResult
    {
        try {
            $manifest = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new ProjectHandoverManifestParseResult(null, 'invalid');
        }

        if (! is_array($manifest)
            || array_is_list($manifest)
            || $this->containsSensitiveKey($manifest)
            || ! $this->hasOnlyKeys($manifest, [
                'schema', 'version', 'exported_at', 'source', 'ownership', 'project', 'products',
                'environments', 'resources', 'connections', 'configuration_references',
                'setup_instructions', 'omitted', 'security',
            ])) {
            return new ProjectHandoverManifestParseResult(null, 'invalid');
        }

        if (($manifest['schema'] ?? null) !== ExportProjectHandoverManifest::SCHEMA
            || ($manifest['version'] ?? null) !== ExportProjectHandoverManifest::VERSION) {
            return new ProjectHandoverManifestParseResult(null, 'unsupported_schema');
        }

        if (! $this->validManifestShape($manifest)) {
            return new ProjectHandoverManifestParseResult(null, 'invalid');
        }

        return new ProjectHandoverManifestParseResult($manifest, 'valid');
    }

    /** @param array<string, mixed> $manifest */
    private function validManifestShape(array $manifest): bool
    {
        if (! $this->isObject($manifest['source'] ?? null)
            || ! $this->hasOnlyKeys($manifest['source'], ['workspace_id', 'workspace_slug', 'project_id', 'project_slug'])
            || ! $this->isObject($manifest['ownership'] ?? null)
            || ! $this->hasOnlyKeys($manifest['ownership'], ['workspace_owner_user_id', 'project_creator_user_id'])
            || ! $this->isObject($manifest['project'] ?? null)
            || ! $this->hasOnlyKeys($manifest['project'], ['name', 'slug'])
            || ! $this->isString($manifest['source']['workspace_id'] ?? null, 26)
            || ! Str::isUlid($manifest['source']['workspace_id'])
            || ! $this->isString($manifest['source']['project_id'] ?? null, 26)
            || ! Str::isUlid($manifest['source']['project_id'])
            || ! $this->isSlug($manifest['source']['workspace_slug'] ?? null)
            || ! $this->isSlug($manifest['source']['project_slug'] ?? null)
            || ! $this->isString($manifest['project']['name'] ?? null, 255)
            || ! $this->isSlug($manifest['project']['slug'] ?? null)
            || ! is_array($manifest['products'] ?? null)
            || ! array_is_list($manifest['products'])
            || count($manifest['products']) > 3
            || count(array_unique($manifest['products'], SORT_REGULAR)) !== count($manifest['products'])
            || ! is_array($manifest['environments'] ?? null)
            || ! array_is_list($manifest['environments'])
            || count($manifest['environments']) > self::MAX_ENVIRONMENTS
            || ! is_array($manifest['resources'] ?? null)
            || ! array_is_list($manifest['resources'])
            || count($manifest['resources']) > self::MAX_RESOURCES
            || ! is_array($manifest['connections'] ?? null)
            || ! array_is_list($manifest['connections'])
            || count($manifest['connections']) > self::MAX_CONNECTIONS
            || ! is_array($manifest['configuration_references'] ?? null)
            || ! array_is_list($manifest['configuration_references'])
            || ! is_array($manifest['setup_instructions'] ?? null)
            || ! $this->isObject($manifest['omitted'] ?? null)
            || ! $this->hasOnlyKeys($manifest['omitted'], ['resource_mappings', 'connection_mappings', 'reason'])
            || ! is_int($manifest['omitted']['resource_mappings'] ?? null)
            || $manifest['omitted']['resource_mappings'] < 0
            || ! is_int($manifest['omitted']['connection_mappings'] ?? null)
            || $manifest['omitted']['connection_mappings'] < 0
            || ! $this->isString($manifest['omitted']['reason'] ?? null, 500)
            || ! $this->isObject($manifest['security'] ?? null)
            || ! $this->hasOnlyKeys($manifest['security'], [
                'secrets_included', 'authentication_credentials_included', 'environment_values_included',
                'subscriptions_included', 'arbitrary_metadata_included', 'is_backup', 'note',
            ])) {
            return false;
        }

        if ($manifest['source']['project_slug'] !== $manifest['project']['slug']) {
            return false;
        }

        foreach (['workspace_owner_user_id', 'project_creator_user_id'] as $key) {
            $value = $manifest['ownership'][$key] ?? null;
            if ($value !== null && (! $this->isString($value, 26) || ! Str::isUlid($value))) {
                return false;
            }
        }

        foreach ($manifest['products'] as $product) {
            if (! is_string($product) || ProductKey::tryFrom($product) === null) {
                return false;
            }
        }

        $environmentRefs = [];
        $environmentSlugs = [];
        foreach ($manifest['environments'] as $environment) {
            if (! $this->isObject($environment)
                || ! $this->hasOnlyKeys($environment, ['ref', 'name', 'slug', 'type'])
                || ! $this->isReference($environment['ref'] ?? null, 'environment_')
                || ! $this->isString($environment['name'] ?? null, 255)
                || ! $this->isSlug($environment['slug'] ?? null)
                || ! $this->isSlug($environment['type'] ?? null, 32)
                || isset($environmentRefs[$environment['ref']])
                || isset($environmentSlugs[$environment['slug']])) {
                return false;
            }

            $environmentRefs[$environment['ref']] = true;
            $environmentSlugs[$environment['slug']] = true;
        }

        $resourceRefs = [];
        $configurationResources = [];
        foreach ($manifest['resources'] as $resource) {
            if (! $this->isObject($resource)
                || ! $this->hasOnlyKeys($resource, [
                    'ref', 'product', 'resource_type', 'local_resource_id', 'name', 'environment_ref',
                ])
                || ! $this->isReference($resource['ref'] ?? null, 'resource_')
                || ! is_string($resource['product'] ?? null)
                || ! in_array($resource['product'], $manifest['products'], true)
                || ! $this->validResourceType($resource['product'], $resource['resource_type'] ?? null)
                || ! $this->isString($resource['local_resource_id'] ?? null, 191)
                || ! $this->optionalString($resource['name'] ?? null, 255)
                || ! $this->optionalReference($resource['environment_ref'] ?? null, 'environment_')
                || (is_string($resource['environment_ref'] ?? null) && ! isset($environmentRefs[$resource['environment_ref']]))
                || isset($resourceRefs[$resource['ref']])) {
                return false;
            }

            $resourceRefs[$resource['ref']] = $resource;
            $configurationResources[$resource['ref']] = $resource['product'];
        }

        $resourceIdentities = [];
        foreach ($resourceRefs as $resource) {
            $identity = $this->resourceIdentity($resource['product'], $resource['resource_type'], $resource['local_resource_id']);
            if (isset($resourceIdentities[$identity])) {
                return false;
            }
            $resourceIdentities[$identity] = true;
        }

        $connectionRefs = [];
        foreach ($manifest['connections'] as $connection) {
            if (! $this->isObject($connection)
                || ! $this->hasOnlyKeys($connection, [
                    'ref', 'source_resource_ref', 'target_resource_ref', 'source_environment_ref',
                    'target_environment_ref', 'capabilities',
                ])
                || ! $this->isReference($connection['ref'] ?? null, 'connection_')
                || ! $this->isReference($connection['source_resource_ref'] ?? null, 'resource_')
                || ! $this->isReference($connection['target_resource_ref'] ?? null, 'resource_')
                || ! isset($resourceRefs[$connection['source_resource_ref']], $resourceRefs[$connection['target_resource_ref']])
                || ! $this->optionalReference($connection['source_environment_ref'] ?? null, 'environment_')
                || ! $this->optionalReference($connection['target_environment_ref'] ?? null, 'environment_')
                || (is_string($connection['source_environment_ref'] ?? null) && ! isset($environmentRefs[$connection['source_environment_ref']]))
                || (is_string($connection['target_environment_ref'] ?? null) && ! isset($environmentRefs[$connection['target_environment_ref']]))
                || ! is_array($connection['capabilities'] ?? null)
                || ! array_is_list($connection['capabilities'])
                || $connection['capabilities'] === []
                || count(array_unique($connection['capabilities'], SORT_REGULAR)) !== count($connection['capabilities'])
                || isset($connectionRefs[$connection['ref']])) {
                return false;
            }

            $connectionRefs[$connection['ref']] = true;
            $source = $resourceRefs[$connection['source_resource_ref']];
            $target = $resourceRefs[$connection['target_resource_ref']];

            foreach ($connection['capabilities'] as $capabilityValue) {
                $capability = is_string($capabilityValue) ? ProjectConnectionCapability::tryFrom($capabilityValue) : null;
                if ($capability === null
                    || ! in_array($capability, ProjectConnectionCapability::supportedBetween($source['product'], $target['product']), true)
                    || $source['resource_type'] !== $capability->sourceResourceType()
                    || $target['resource_type'] !== $capability->targetResourceType()) {
                    return false;
                }
            }
        }

        $configurationRefs = [];
        foreach ($manifest['configuration_references'] as $reference) {
            if (! $this->isObject($reference)
                || ! $this->hasOnlyKeys($reference, ['product', 'resource_ref', 'kind', 'values_included'])
                || ! is_string($reference['product'] ?? null)
                || ! isset($configurationResources[$reference['resource_ref'] ?? ''])
                || $configurationResources[$reference['resource_ref']] !== $reference['product']
                || ! is_string($reference['kind'] ?? null)
                || ! $this->validConfigurationKind($reference['product'], $reference['kind'])
                || ($reference['values_included'] ?? null) !== false
                || isset($configurationRefs[$reference['resource_ref']])) {
                return false;
            }

            $configurationRefs[$reference['resource_ref']] = true;
        }

        foreach ($manifest['products'] as $product) {
            if (! is_array($manifest['setup_instructions'][$product] ?? null)
                || ! array_is_list($manifest['setup_instructions'][$product])) {
                return false;
            }

            foreach ($manifest['setup_instructions'][$product] as $instruction) {
                if (! $this->isString($instruction, 1000)) {
                    return false;
                }
            }
        }

        foreach ($manifest['setup_instructions'] as $product => $instructions) {
            if (! in_array($product, $manifest['products'], true) || ! is_array($instructions)) {
                return false;
            }
        }

        foreach ([
            'secrets_included', 'authentication_credentials_included', 'environment_values_included',
            'subscriptions_included', 'arbitrary_metadata_included', 'is_backup',
        ] as $key) {
            if (($manifest['security'][$key] ?? null) !== false) {
                return false;
            }
        }

        return $this->isString($manifest['security']['note'] ?? null, 500)
            && (! array_key_exists('exported_at', $manifest) || $this->isString($manifest['exported_at'], 64));
    }

    private function containsSensitiveKey(array $value, string $path = ''): bool
    {
        $safeSecurityKeys = [
            'secrets_included', 'authentication_credentials_included', 'environment_values_included',
            'subscriptions_included', 'arbitrary_metadata_included',
        ];

        foreach ($value as $key => $nested) {
            if (is_string($key)) {
                $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
                $safeSecurityFlag = $path === 'security' && in_array($key, $safeSecurityKeys, true);
                if (! $safeSecurityFlag && preg_match('/password|secret|token|credential|privatekey|authorization|apikey|appkey|connectionstring|dsn|session|metadata|settings|cookie|passphrase/', $normalized) === 1) {
                    return true;
                }
            }

            if (is_array($nested) && $this->containsSensitiveKey($nested, $path === '' ? (string) $key : $path.'.'.$key)) {
                return true;
            }
        }

        return false;
    }

    private function isObject(mixed $value): bool
    {
        return is_array($value) && ! array_is_list($value);
    }

    /** @param array<mixed> $value
     * @param  list<string>  $allowed
     */
    private function hasOnlyKeys(array $value, array $allowed): bool
    {
        foreach (array_keys($value) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private function isString(mixed $value, int $maxLength): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= $maxLength;
    }

    private function optionalString(mixed $value, int $maxLength): bool
    {
        return $value === null || $this->isString($value, $maxLength);
    }

    private function isSlug(mixed $value, int $maxLength = 120): bool
    {
        return $this->isString($value, $maxLength) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1;
    }

    private function isReference(mixed $value, string $prefix): bool
    {
        return is_string($value)
            && preg_match('/^'.preg_quote($prefix, '/').'[0-9A-HJKMNP-TV-Z]{26}$/', $value) === 1;
    }

    private function optionalReference(mixed $value, string $prefix): bool
    {
        return $value === null || $this->isReference($value, $prefix);
    }

    private function validResourceType(string $product, mixed $type): bool
    {
        $allowed = match ($product) {
            'deployer' => ['project', 'environment'],
            'monitor' => ['application', 'environment'],
            'analytics' => ['site'],
            default => [],
        };

        return is_string($type) && in_array($type, $allowed, true);
    }

    private function validConfigurationKind(string $product, string $kind): bool
    {
        return match ($product) {
            'deployer' => $kind === 'deployment_configuration',
            'monitor' => $kind === 'monitoring_configuration',
            'analytics' => $kind === 'analytics_site_configuration',
            default => false,
        };
    }

    private function resourceIdentity(string $product, string $type, string $id): string
    {
        return hash('sha256', json_encode([$product, $type, $id], JSON_THROW_ON_ERROR));
    }
}
