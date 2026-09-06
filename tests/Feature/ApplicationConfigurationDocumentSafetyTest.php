<?php

namespace Tests\Feature;

use App\Services\ApplicationConfigurationDocument;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ApplicationConfigurationDocumentSafetyTest extends TestCase
{
    public function test_aliases_merge_keys_and_excessive_nesting_are_rejected_before_validation(): void
    {
        $factory = app('validator');
        // Sanitized exceptions may create an empty validator; submitted data must
        // never reach Laravel's wildcard expansion for these parser failures.
        Validator::shouldReceive('make')->with([], [])->andReturnUsing(fn () => $factory->make([], []));
        foreach ([
            "version: 2\nenvironments:\n  staging: &settings\n    type: staging\n    placement: site\n    runtime: {type: php}\n  development: *settings\n",
            "version: 2\nenvironments:\n  staging:\n    type: &name staging\n    placement: *name\n    runtime: {type: php}\n",
            "version: 2\nenvironments:\n  staging: &settings {type: staging, placement: site, runtime: {type: php}}\n  development: {<<: *settings}\n",
            'version: 2'."\nenvironments: ".str_repeat('[', 200).'private-value'.str_repeat(']', 200),
            "version: 2\nenvironments:\n".implode("\n", array_map(fn ($depth) => str_repeat('  ', $depth).'nested:', range(1, 100))),
            "version: 2\nenvironments: &recursive [*recursive]\n",
        ] as $yaml) {
            $this->assertInvalid($yaml);
        }
    }

    public function test_quoted_and_block_commands_can_contain_yaml_metacharacters(): void
    {
        $yaml = <<<'YAML'
version: 2
environments:
  staging:
    type: staging
    placement: site
    runtime:
      type: node
      port: 3000
      start_command: 'echo "&anchor *alias [nested] !php/object"'
      build_command: |
        echo "<<: *reference"
        npm ci && npm run build
YAML;
        $runtime = app(ApplicationConfigurationDocument::class)->parse($yaml)['environments']['staging']['runtime'];
        $this->assertSame('echo "&anchor *alias [nested] !php/object"', $runtime['start_command']);
        $this->assertStringContainsString('<<: *reference', $runtime['build_command']);
    }

    public function test_runtime_requirements_match_environment_validation_without_coercion(): void
    {
        foreach (['node', 'python'] as $type) {
            foreach ([null, '', '   ', "\t\n"] as $command) {
                $this->assertInvalid($this->yaml(['type' => $type, 'port' => 3000, 'start_command' => $command]));
            }
            foreach ([1, 65535] as $port) {
                $runtime = ['type' => $type, 'port' => $port, 'start_command' => '0'];
                $this->assertSame($runtime, app(ApplicationConfigurationDocument::class)->parse($this->yaml($runtime))['environments']['staging']['runtime']);
            }
            foreach ([null, 0, 65536, '3000', 3000.0, true] as $port) {
                $this->assertInvalid($this->yaml(['type' => $type, 'port' => $port, 'start_command' => 'npm start']));
            }
        }
        foreach ([null, '', ' ', '/Dockerfile', '../Dockerfile', 'folder/../Dockerfile', 'Docker file', 'folder\\Dockerfile'] as $path) {
            $this->assertInvalid($this->yaml(['type' => 'docker', 'port' => 8080, 'dockerfile_path' => $path]));
        }
        $runtime = ['type' => 'docker', 'port' => 8080, 'dockerfile_path' => 'deploy/Dockerfile.prod'];
        $this->assertSame($runtime, app(ApplicationConfigurationDocument::class)->parse($this->yaml($runtime))['environments']['staging']['runtime']);
        $this->assertInvalid($this->yaml(['type' => 'php', 'build_command' => str_repeat('a', 2001)]));
    }

    public function test_named_processes_and_resources_follow_existing_fifty_character_limit(): void
    {
        foreach (['processes' => ['type' => 'worker', 'command' => 'php worker', 'replicas' => 20], 'resources' => ['type' => 'redis', 'managed' => true]] as $kind => $settings) {
            $valid = $this->document(['type' => 'php']);
            $valid['environments']['staging'][$kind] = [str_repeat('a', 50) => $settings];
            $this->assertSame($valid, app(ApplicationConfigurationDocument::class)->parse(Yaml::dump($valid, 10)));
            $valid['environments']['staging'][$kind] = [str_repeat('a', 51) => $settings];
            $this->assertInvalid(Yaml::dump($valid, 10));
        }
        foreach ([0, 21, '1', 1.0, true] as $replicas) {
            $document = $this->document(['type' => 'php']);
            $document['environments']['staging']['processes']['worker'] = ['type' => 'worker', 'command' => 'run', 'replicas' => $replicas];
            $this->assertInvalid(Yaml::dump($document, 10));
        }
        $document['environments']['staging']['processes']['worker'] = ['type' => 'scheduler', 'command' => 'run', 'replicas' => 2];
        $this->assertInvalid(Yaml::dump($document, 10));
    }

    public function test_resource_credentials_require_external_resources_and_named_secret_references(): void
    {
        foreach ([
            ['type' => 'object_storage', 'managed' => true],
            ['type' => 'redis', 'managed' => true, 'variable_refs' => []],
            ['type' => 'mysql', 'managed' => false, 'variable_refs' => ['db_password' => 'token']],
            ['type' => 'mysql', 'managed' => false, 'variable_refs' => ['DB_PASSWORD' => 'private value']],
            ['type' => 'mysql', 'managed' => false, 'variable_refs' => ['token']],
            ['type' => 'mysql', 'managed' => false, 'variables' => ['DB_PASSWORD' => 'private-value']],
        ] as $resource) {
            $document = $this->document(['type' => 'php']);
            $document['environments']['staging']['resources']['database'] = $resource;
            $this->assertInvalid(Yaml::dump($document, 10));
        }
        $document['environments']['staging']['resources']['database'] = ['type' => 'mysql', 'managed' => false, 'variable_refs' => []];
        $this->assertSame($document, app(ApplicationConfigurationDocument::class)->parse(Yaml::dump($document, 10)));
    }

    public function test_multiple_managed_valkey_resources_cannot_reserve_the_same_environment_port(): void
    {
        $document = $this->document(['type' => 'php']);
        $document['environments']['staging']['resources'] = [
            'cache' => ['type' => 'valkey', 'managed' => true],
            'sessions' => ['type' => 'valkey', 'managed' => true],
        ];
        $this->assertInvalid(Yaml::dump($document, 10));
        $document['environments']['staging']['resources']['sessions']['managed'] = false;
        $this->assertSame($document, app(ApplicationConfigurationDocument::class)->parse(Yaml::dump($document, 10)));
    }

    private function yaml(array $runtime): string
    {
        return Yaml::dump($this->document($runtime), 10);
    }

    private function document(array $runtime): array
    {
        return ['version' => 2, 'environments' => ['staging' => ['type' => 'staging', 'placement' => 'site', 'runtime' => $runtime]]];
    }

    private function assertInvalid(string $yaml): void
    {
        try {
            app(ApplicationConfigurationDocument::class)->parse($yaml);
            $this->fail('Invalid application configuration was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['document' => ['Invalid version 2 application configuration.']], $exception->errors());
        }
    }
}
