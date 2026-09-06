<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;

class ApplicationConfigurationDocument
{
    public function parse(string $yaml): array
    {
        if (strlen($yaml) > 50000) {
            $this->invalid();
        }
        try {
            // Limit nesting while parsing, before PHP arrays or validation rules
            // can expand. Aliases (including merge keys) are not part of v2.
            $document = (new Parser(maxNestingLevel: 12, maxAliasesForCollections: 0))
                ->parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS);
        } catch (ParseException) {
            // Parser diagnostics can contain submitted secrets or commands.
            $this->invalid();
        }
        if (! is_array($document) || ($document['version'] ?? null) !== 2) {
            $this->invalid();
        }
        $this->boundStructure($document);
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
        $removedEnvironments = $document['remove']['environments'] ?? [];
        if (($document['environments'] === [] && $removedEnvironments === [])
            || ! array_is_list($removedEnvironments)
            || count(array_unique($removedEnvironments)) !== count($removedEnvironments)) {
            $this->invalid();
        }
        foreach ($removedEnvironments as $slug) {
            $this->name($slug);
            if (array_key_exists($slug, $document['environments'])) {
                $this->invalid();
            }
        }

        foreach ($document['environments'] as $name => $environment) {
            $this->name($name);
            $this->adoption($environment);
            if (array_key_exists('deploy', $environment) && ! isset($environment['deploy']['repository'])) {
                $this->invalid();
            }
            foreach ($environment['remove'] ?? [] as $kind => $names) {
                if (! array_is_list($names) || count(array_unique($names)) !== count($names)) {
                    $this->invalid();
                }
                foreach ($names as $name) {
                    $this->name($name, $kind === 'variables', $kind === 'variables' ? 100 : 50);
                    if (array_key_exists($name, $environment[$kind] ?? [])) {
                        $this->invalid();
                    }
                }
            }
            foreach (['processes', 'resources', 'variables'] as $collection) {
                foreach ($environment[$collection] ?? [] as $key => $settings) {
                    $this->name($key, $collection === 'variables', $collection === 'variables' ? 100 : 50);
                    $this->adoption($settings);
                }
            }
            $runtime = $environment['runtime'];
            if (isset($runtime['port']) && ! is_int($runtime['port'])) {
                $this->invalid();
            }
            if (in_array($runtime['type'], ['node', 'python'], true) && trim($runtime['start_command'] ?? '') === '') {
                $this->invalid();
            }
            if ($runtime['type'] !== 'php' && empty($runtime['port'])) {
                $this->invalid();
            }
            if ($runtime['type'] === 'docker' && trim($runtime['dockerfile_path'] ?? '') === '') {
                $this->invalid();
            }
            foreach ($environment['processes'] ?? [] as $process) {
                if (! is_int($process['replicas'])) {
                    $this->invalid();
                }
                if ($process['type'] === 'scheduler' && $process['replicas'] !== 1) {
                    $this->invalid();
                }
            }
            $managedValkeyCount = 0;
            foreach ($environment['resources'] ?? [] as $resource) {
                foreach (array_keys($resource['variable_refs'] ?? []) as $key) {
                    $this->name($key, true);
                }
                if ($resource['managed'] && array_key_exists('variable_refs', $resource)) {
                    $this->invalid();
                }
                if (! is_bool($resource['managed'])) {
                    $this->invalid();
                }
                if ($resource['managed'] && $resource['type'] === 'object_storage') {
                    $this->invalid();
                }
                // Managed Valkey currently reserves one port per environment.
                if ($resource['managed'] && $resource['type'] === 'valkey' && ++$managedValkeyCount > 1) {
                    $this->invalid();
                }
            }
        }

        return $document;
    }

    private function name(mixed $name, bool $variable = false, int $maximumLength = 100): void
    {
        if (! is_string($name) || strlen($name) > $maximumLength || ! preg_match($variable ? '/\A[A-Z_][A-Z0-9_]*\z/' : '/\A[a-z][a-z0-9_-]*\z/', $name)) {
            $this->invalid();
        }
    }

    private function adoption(array $settings): void
    {
        if (array_key_exists('adopt', $settings) && ! is_bool($settings['adopt'])) {
            $this->invalid();
        }
    }

    private function boundStructure(array $document): void
    {
        // Bound expanded YAML data before Laravel expands wildcard validation rules.
        $pending = [[$document, 0]];
        $nodes = 0;
        while ($pending !== []) {
            [$value, $depth] = array_pop($pending);
            if (++$nodes > 10000 || $depth > 12) {
                $this->invalid();
            }
            if (is_array($value)) {
                if (count($value) + count($pending) > 10000) {
                    $this->invalid();
                }
                foreach ($value as $child) {
                    $pending[] = [$child, $depth + 1];
                }
            }
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['document' => 'Invalid version 2 application configuration.']);
    }
}
