<?php

declare(strict_types=1);

namespace App\Services\Deploy\Configuration;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses and validates a version 2 configuration document (ported from Deployer): environments keyed by slug, each with
 * a type, a placement (a bound website), a runtime, processes, resources, variables (bound secrets), optional adoption,
 * removals and a deploy (a bound repository), plus environments to remove. Errors never echo the document.
 */
final class ConfigurationDocument
{
    /**
     * Parses a version 2 document of at most 50 KB, refusing YAML aliases and deep nesting before anything expands, and
     * validates its shape and every name, runtime, process and resource rule.
     *
     * @return array{version: int, environments: array<string, array<string, mixed>>, remove?: array{environments?: list<string>}}
     */
    public function parse(string $yaml): array
    {
        if (strlen($yaml) > 50000) {
            $this->invalid();
        }
        try {
            // Aliases are refused and nesting bounded before any PHP arrays expand.
            $document = (new Parser(maxNestingLevel: 12, maxAliasesForCollections: 0))->parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS);
        } catch (ParseException) {
            $this->invalid();
        }
        if (! is_array($document) || ($document['version'] ?? null) !== 2) {
            $this->invalid();
        }
        $this->bound($document);
        $validator = Validator::make(['document' => $document], [
            'document' => 'required|array:version,environments,remove',
            'document.environments' => 'sometimes|array|max:20',
            'document.remove' => 'sometimes|array:environments',
            'document.remove.environments' => 'sometimes|array|min:1|max:20',
            'document.remove.environments.*' => 'required|string|max:100',
            'document.environments.*' => 'required|array:type,placement,runtime,processes,resources,variables,adopt,remove,deploy',
            'document.environments.*.deploy' => 'sometimes|array:repository',
            'document.environments.*.deploy.repository' => 'required_with:document.environments.*.deploy|string|max:100|regex:/\A[a-z][a-z0-9_-]*\z/',
            'document.environments.*.remove' => 'sometimes|array:processes,resources,variables',
            'document.environments.*.remove.*' => 'array|max:100',
            'document.environments.*.remove.*.*' => 'required|string|max:100',
            'document.environments.*.adopt' => 'sometimes|boolean',
            'document.environments.*.type' => 'required|in:production,staging,development,preview',
            'document.environments.*.placement' => 'required|string|max:100|regex:/\A[a-z][a-z0-9_-]*\z/',
            'document.environments.*.runtime' => 'required|array:type,build_command,start_command,port,dockerfile_path',
            'document.environments.*.runtime.type' => 'required|in:php,node,python,docker',
            'document.environments.*.runtime.build_command' => 'nullable|string|max:2000',
            'document.environments.*.runtime.start_command' => 'nullable|string|max:2000',
            'document.environments.*.runtime.port' => 'nullable|integer|between:1,65535',
            'document.environments.*.runtime.dockerfile_path' => ['nullable', 'string', 'max:255', 'regex:/\A(?!\/)(?!.*\.\.)(?:[A-Za-z0-9_.-]+\/)*[A-Za-z0-9_.-]+\z/'],
            'document.environments.*.processes' => 'sometimes|array|max:50',
            'document.environments.*.processes.*' => 'array:type,command,replicas,adopt',
            'document.environments.*.processes.*.adopt' => 'sometimes|boolean',
            'document.environments.*.processes.*.type' => 'required|in:worker,scheduler',
            'document.environments.*.processes.*.command' => 'required|string|max:2000',
            'document.environments.*.processes.*.replicas' => 'required|integer|between:1,20',
            'document.environments.*.resources' => 'sometimes|array|max:50',
            'document.environments.*.resources.*' => 'array:type,managed,adopt,variable_refs',
            'document.environments.*.resources.*.variable_refs' => 'sometimes|array|max:100',
            'document.environments.*.resources.*.variable_refs.*' => 'required|string|max:100|regex:/\A[a-z][a-z0-9_-]*\z/',
            'document.environments.*.resources.*.adopt' => 'sometimes|boolean',
            'document.environments.*.resources.*.type' => 'required|in:mysql,postgresql,redis,valkey,object_storage',
            'document.environments.*.resources.*.managed' => 'required|boolean',
            'document.environments.*.variables' => 'sometimes|array|max:100',
            'document.environments.*.variables.*' => 'array:secret_ref,scope,adopt',
            'document.environments.*.variables.*.adopt' => 'sometimes|boolean',
            'document.environments.*.variables.*.secret_ref' => 'required|string|max:100|regex:/\A[a-z][a-z0-9_-]*\z/',
            'document.environments.*.variables.*.scope' => 'required|in:runtime,build,all',
        ]);
        if ($validator->fails()) {
            $this->invalid();
        }
        $document['environments'] ??= [];
        $removed = $document['remove']['environments'] ?? [];
        if (($document['environments'] === [] && $removed === []) || ! array_is_list($removed) || count(array_unique($removed)) !== count($removed)) {
            $this->invalid();
        }
        foreach ($removed as $slug) {
            $this->name($slug);
            if (array_key_exists($slug, $document['environments'])) {
                $this->invalid();
            }
        }
        foreach ($document['environments'] as $slug => $environment) {
            $this->check($slug, $environment);
        }

        /** @var array{version: int, environments: array<string, array<string, mixed>>, remove?: array{environments?: list<string>}} $document */
        return $document;
    }

    /**
     * Validates one environment: names, adoption flags, removals that don't also appear as desired objects, runtime
     * requirements per type, single-replica schedulers, and managed resources (no variable references, no managed object
     * storage, at most one managed Valkey).
     *
     * @param  array<string, mixed>  $environment
     */
    private function check(mixed $slug, array $environment): void
    {
        $this->name($slug);
        $this->adoption($environment);
        foreach ((array) ($environment['remove'] ?? []) as $kind => $names) {
            if (! is_array($names) || ! array_is_list($names) || count(array_unique($names)) !== count($names)) {
                $this->invalid();
            }
            foreach ($names as $name) {
                $this->name($name, $kind === 'variables', $kind === 'variables' ? 100 : 50);
                if (array_key_exists($name, (array) ($environment[$kind] ?? []))) {
                    $this->invalid();
                }
            }
        }
        foreach (['processes', 'resources', 'variables'] as $collection) {
            foreach ((array) ($environment[$collection] ?? []) as $key => $settings) {
                $this->name($key, $collection === 'variables', $collection === 'variables' ? 100 : 50);
                $this->adoption((array) $settings);
            }
        }
        $runtime = (array) $environment['runtime'];
        if ((isset($runtime['port']) && ! is_int($runtime['port']))
            || (in_array($runtime['type'], ['node', 'python'], true) && trim((string) ($runtime['start_command'] ?? '')) === '')
            || ($runtime['type'] !== 'php' && empty($runtime['port']))
            || ($runtime['type'] === 'docker' && trim((string) ($runtime['dockerfile_path'] ?? '')) === '')) {
            $this->invalid();
        }
        foreach ((array) ($environment['processes'] ?? []) as $process) {
            if (! is_int($process['replicas']) || ($process['type'] === 'scheduler' && $process['replicas'] !== 1)) {
                $this->invalid();
            }
        }
        $valkey = 0;
        foreach ((array) ($environment['resources'] ?? []) as $resource) {
            foreach (array_keys((array) ($resource['variable_refs'] ?? [])) as $key) {
                $this->name($key, true);
            }
            if (! is_bool($resource['managed']) || ($resource['managed'] && array_key_exists('variable_refs', $resource))
                || ($resource['managed'] && $resource['type'] === 'object_storage')
                || ($resource['managed'] && $resource['type'] === 'valkey' && ++$valkey > 1)) {
                $this->invalid();
            }
        }
    }

    /**
     * Validates a name: lowercase slugs for objects, upper-case keys for variables.
     */
    private function name(mixed $name, bool $variable = false, int $maximum = 100): void
    {
        if (! is_string($name) || strlen($name) > $maximum || preg_match($variable ? '/\A[A-Z_][A-Z0-9_]*\z/' : '/\A[a-z][a-z0-9_-]*\z/', $name) !== 1) {
            $this->invalid();
        }
    }

    /**
     * Validates an `adopt` flag is a boolean.
     *
     * @param  array<mixed>  $settings
     */
    private function adoption(array $settings): void
    {
        if (array_key_exists('adopt', $settings) && ! is_bool($settings['adopt'])) {
            $this->invalid();
        }
    }

    /** Bound the expanded data (10,000 nodes, depth 12) before wildcard validation rules expand it. */
    private function bound(mixed $document): void
    {
        $pending = [[$document, 0]];
        $nodes = 0;
        while ($pending !== []) {
            [$value, $depth] = array_pop($pending);
            if (++$nodes > 10000 || $depth > 12 || (is_array($value) && count($value) + count($pending) > 10000)) {
                $this->invalid();
            }
            foreach (is_array($value) ? $value : [] as $child) {
                $pending[] = [$child, $depth + 1];
            }
        }
    }

    /**
     * Refuses the document with a fixed message that never echoes its content.
     */
    private function invalid(): never
    {
        throw ValidationException::withMessages(['document' => 'Invalid version 2 application configuration.']);
    }
}
