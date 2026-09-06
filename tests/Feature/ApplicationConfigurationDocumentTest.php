<?php

namespace Tests\Feature;

use App\Services\ApplicationConfigurationDocument;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApplicationConfigurationDocumentTest extends TestCase
{
    public function test_valid_document_preserves_logical_bindings(): void
    {
        $document = app(ApplicationConfigurationDocument::class)->parse("version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: staging_site\n    runtime:\n      type: node\n      port: 3000\n      start_command: npm start\n");
        $this->assertSame('staging_site', $document['environments']['staging']['placement']);
        $this->assertSame(3000, $document['environments']['staging']['runtime']['port']);
    }

    public function test_invalid_documents_are_rejected(): void
    {
        $base = "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: staging_site\n    runtime:\n      type: php\n";
        foreach ([str_replace('version: 2', 'version: 1', $base), $base."      typo: true\n", str_replace('type: php', 'type: node', $base), $base."      type: docker\n", str_repeat('x', 50001)] as $yaml) {
            try {
                app(ApplicationConfigurationDocument::class)->parse($yaml);
                $this->fail('Invalid document was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_expanded_structure_is_bounded_before_wildcard_validation(): void
    {
        foreach ([
            "version: 2\nenvironments: ".str_repeat('[', 20).'value'.str_repeat(']', 20),
            "version: 2\nenvironments: [".implode(',', array_fill(0, 10001, 'x')).']',
        ] as $yaml) {
            try {
                app(ApplicationConfigurationDocument::class)->parse($yaml);
                $this->fail('Unbounded structure accepted.');
            } catch (ValidationException $exception) {
                $this->assertSame(['document' => ['Invalid version 2 application configuration.']], $exception->errors());
            }
        }
    }

    public function test_deployment_is_explicit_and_requires_a_logical_repository_reference(): void
    {
        $base = "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: site\n    runtime:\n      type: php\n";
        $document = app(ApplicationConfigurationDocument::class)->parse($base."    deploy:\n      repository: app\n");
        $this->assertSame('app', $document['environments']['staging']['deploy']['repository']);
        foreach (["    deploy: true\n", "    deploy: {}\n", "    deploy: {unexpected: app}\n"] as $suffix) {
            try {
                app(ApplicationConfigurationDocument::class)->parse($base.$suffix);
                $this->fail('Invalid deployment request accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('document', $exception->errors());
            }
        }
    }

    public function test_removal_rejects_duplicates_maps_unknown_kinds_and_conflicting_declarations(): void
    {
        $base = "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: site\n    runtime:\n      type: php\n";
        foreach ([
            "    remove:\n      resources: [cache, cache]\n",
            "    remove:\n      resources: {name: cache}\n",
            "    remove:\n      servers: [server]\n",
            "    remove:\n      variables: [invalid-name]\n",
            "    remove:\n      resources: [cache]\n    resources:\n      cache:\n        type: redis\n        managed: true\n",
        ] as $suffix) {
            try {
                app(ApplicationConfigurationDocument::class)->parse($base.$suffix);
                $this->fail('Invalid removal accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('document', $exception->errors());
            }
        }
    }

    public function test_validation_does_not_echo_submitted_keys_and_rejects_coerced_types(): void
    {
        $base = "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: staging_site\n    runtime:\n      type: php\n";
        foreach ([
            str_replace('staging:', 'private-token-value:', $base)."      private-token-value: true\n",
            $base."      port: '3000'\n",
            $base."    resources:\n      cache:\n        type: redis\n        managed: '1'\n",
            $base."    processes:\n      worker:\n        type: worker\n        command: private-token-value\n        replicas: '2'\n",
            "version: 2\nenvironments: !php/object private-token-value\n",
        ] as $yaml) {
            try {
                app(ApplicationConfigurationDocument::class)->parse($yaml);
                $this->fail('Invalid document was accepted.');
            } catch (ValidationException $exception) {
                $this->assertSame(['document' => ['Invalid version 2 application configuration.']], $exception->errors());
            }
        }
    }
}
