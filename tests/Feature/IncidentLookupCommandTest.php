<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class IncidentLookupCommandTest extends TestCase
{
    private string $logDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lessbuild-incident-'.Str::uuid();
        File::makeDirectory($this->logDirectory, 0700, true);
        config(['lessbuild.incident_log_directory' => $this->logDirectory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logDirectory);

        parent::tearDown();
    }

    public function test_operator_can_locate_a_reference_in_rotated_logs_without_printing_exception_details(): void
    {
        $reference = (string) Str::uuid();
        $older = $this->logDirectory.DIRECTORY_SEPARATOR.'laravel-2026-09-03.log';
        $newer = $this->logDirectory.DIRECTORY_SEPARATOR.'laravel.log';
        File::put($older, implode("\n", [
            '[2026-09-03 12:00:00] production.INFO: Routine message. []',
            '[2026-09-03 12:01:02] production.ERROR: Unhandled application exception. {"incident_id":"'.$reference.'","exception":"super-secret exception detail"}',
            '#0 private stack trace',
        ]));
        File::put($newer, '[2026-09-04 08:00:00] production.INFO: Newer unrelated entry. []');
        touch($older, time() - 60);
        touch($newer, time());

        $this->artisan('lessbuild:incident', ['reference' => strtoupper($reference)])
            ->expectsOutput("Incident {$reference} was found.")
            ->expectsOutput('Timestamp: 2026-09-03 12:01:02')
            ->expectsOutput('Environment: production')
            ->expectsOutput('Level: ERROR')
            ->expectsOutput("Log location: {$older}:2")
            ->doesntExpectOutputToContain('super-secret')
            ->doesntExpectOutputToContain('private stack')
            ->assertSuccessful();
    }

    public function test_newest_matching_retained_log_is_reported_first(): void
    {
        $reference = (string) Str::uuid();
        $older = $this->logDirectory.DIRECTORY_SEPARATOR.'laravel-2026-09-03.log';
        $newer = $this->logDirectory.DIRECTORY_SEPARATOR.'laravel.log';
        File::put($older, '[2026-09-03 12:00:00] production.ERROR: Error. {"incident_id":"'.$reference.'"}');
        File::put($newer, '[2026-09-04 08:00:00] production.ERROR: Error. {"incident_id":"'.$reference.'"}');
        touch($older, time() - 60);
        touch($newer, time());

        $this->artisan('lessbuild:incident', ['reference' => $reference])
            ->expectsOutput("Log location: {$newer}:1")
            ->doesntExpectOutputToContain($older)
            ->assertSuccessful();
    }

    public function test_invalid_missing_and_unavailable_references_fail_safely(): void
    {
        $this->artisan('lessbuild:incident', ['reference' => '../../laravel.log'])
            ->expectsOutput('The incident reference must be a valid version 4 UUID.')
            ->assertFailed();

        $missing = (string) Str::uuid();
        $this->artisan('lessbuild:incident', ['reference' => $missing])
            ->expectsOutput("Incident {$missing} was not found in the retained Laravel logs.")
            ->assertFailed();

        config(['lessbuild.incident_log_directory' => $this->logDirectory.DIRECTORY_SEPARATOR.'missing']);
        $this->artisan('lessbuild:incident', ['reference' => (string) Str::uuid()])
            ->expectsOutput('The configured incident log directory is unavailable.')
            ->assertFailed();
    }

    public function test_daily_channel_writes_a_dated_log_in_the_incident_lookup_directory(): void
    {
        config([
            'logging.channels.daily.path' => $this->logDirectory.DIRECTORY_SEPARATOR.'laravel.log',
            'logging.channels.daily.days' => 14,
        ]);
        Log::forgetChannel('daily');

        Log::channel('daily')->error('Rotation probe.', [
            'incident_id' => (string) Str::uuid(),
        ]);

        $this->assertFileExists(
            $this->logDirectory.DIRECTORY_SEPARATOR.'laravel-'.now()->format('Y-m-d').'.log',
        );
    }
}
