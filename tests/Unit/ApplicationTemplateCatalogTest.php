<?php

namespace Tests\Unit;

use App\Data\ApplicationTemplateDefinition;
use App\Services\ApplicationTemplateCatalog;
use InvalidArgumentException;
use Tests\TestCase;

class ApplicationTemplateCatalogTest extends TestCase
{
    public function test_laravel_template_has_a_complete_versioned_operational_contract(): void
    {
        $template = app(ApplicationTemplateCatalog::class)->for('laravel');

        $this->assertInstanceOf(ApplicationTemplateDefinition::class, $template);
        $this->assertSame('laravel', $template->key);
        $this->assertSame('1.0.0', $template->version());
        $this->assertSame([
            'runtime' => 'php',
            'framework' => 'laravel',
            'php' => '8.2 - 8.5',
            'deployment' => 'BuildPusher managed website',
        ], $template->serviceTemplate?->compatibility);
        $this->assertSame(['database', 'cache'], array_column($template->serviceTemplate?->resources ?? [], 'name'));
        $this->assertSame(['generated', 'generated'], array_column($template->serviceTemplate?->resources ?? [], 'credentials'));
        $this->assertSame(['database', 'cache'], array_column($template->serviceTemplate?->persistentData ?? [], 'name'));
        $this->assertSame(['web', 'queue', 'scheduler', 'database', 'cache'], array_column($template->serviceTemplate?->readinessChecks ?? [], 'name'));
        $this->assertSame(2, $template->serviceTemplate?->resourceLimits['resources']);
        $this->assertArrayHasKey('database', $template->serviceTemplate?->backupRestore ?? []);
        $this->assertArrayHasKey('policy', $template->serviceTemplate?->upgrade ?? []);
        $this->assertArrayHasKey('initialization', $template->serviceTemplate?->failureRecovery ?? []);
        $this->assertArrayHasKey('retention', $template->serviceTemplate?->deletion ?? []);
    }

    public function test_non_curated_presets_keep_existing_runtime_data_without_a_service_contract(): void
    {
        $template = app(ApplicationTemplateCatalog::class)->for('nextjs');

        $this->assertSame('nextjs', $template->key);
        $this->assertSame('node', $template->runtimeType);
        $this->assertSame('npm run build', $template->buildCommand);
        $this->assertSame('npm start', $template->startCommand);
        $this->assertNull($template->serviceTemplate);
        $this->assertNull($template->version());
    }

    public function test_unknown_presets_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The application preset is not configured.');

        app(ApplicationTemplateCatalog::class)->for('missing');
    }
}
