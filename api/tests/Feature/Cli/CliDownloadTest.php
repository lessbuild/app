<?php

declare(strict_types=1);

namespace Tests\Feature\Cli;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class CliDownloadTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that the CLI and its installer are served as plain text, the installer fetches this installation's copy,
     * and the script is valid PHP that prints its help.
     *
     * @return void
     */
    public function test_the_cli_and_its_installer_are_served(): void
    {
        $this->get('/cli/buildpusher')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringStartsWith("#!/usr/bin/env php\n<?php", (string) file_get_contents(resource_path('cli/buildpusher.php')));

        $installer = $this->get('/cli/install.sh')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringStartsWith("#!/bin/sh\n", (string) $installer->getContent());
        $this->assertStringContainsString('curl -fsSL "'.route('cli.download').'"', (string) $installer->getContent());

        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg(resource_path('cli/buildpusher.php')), $lint, $code);
        $this->assertSame(0, $code, implode("\n", $lint));
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(resource_path('cli/buildpusher.php')).' help', $help, $code);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('buildpusher deploy <project> [environment] [--wait]', implode("\n", $help));

        $this->getJson('/api/app/help/use-the-cli')->assertOk()->assertJsonPath('title', 'Deploy from the command line');
        $this->actingAs($this->ownerOf(Project::factory()->create()))->getJson('/api/app/account/api-tokens')->assertOk()->assertJsonPath('cliInstallUrl', route('cli.install'));
    }
}
