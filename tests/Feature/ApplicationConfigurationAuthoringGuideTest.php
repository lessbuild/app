<?php

namespace Tests\Feature;

use App\Services\ApplicationConfigurationAuthoringGuide;
use App\Services\ApplicationConfigurationDocument;
use Tests\TestCase;

class ApplicationConfigurationAuthoringGuideTest extends TestCase
{
    public function test_starter_document_is_accepted_by_the_version_two_parser_and_contains_no_secrets(): void
    {
        $guide = app(ApplicationConfigurationAuthoringGuide::class)->for();
        $parsed = app(ApplicationConfigurationDocument::class)->parse($guide['document']);

        $this->assertSame(2, $parsed['version']);
        $this->assertSame('staging', $parsed['environments']['staging']['type']);
        $this->assertSame('staging_site', $parsed['environments']['staging']['placement']);
        $this->assertSame(['placements' => ['staging_site' => 12], 'secrets' => [], 'repositories' => []], json_decode($guide['bindings'], true, 20, JSON_THROW_ON_ERROR));
        $serialized = json_encode($guide, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('password', $serialized);
        $this->assertStringNotContainsString('token', $serialized);
    }
}
