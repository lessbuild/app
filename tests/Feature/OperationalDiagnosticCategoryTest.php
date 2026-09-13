<?php

namespace Tests\Feature;

use App\Enums\OperationalDiagnosticCategory;
use App\Services\OperationalDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalDiagnosticCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_typed_report_covers_existing_checks_and_run_preserves_the_legacy_projection(): void
    {
        config(['lessbuild.diagnostics.systemd_timers' => false]);

        $diagnostics = app(OperationalDiagnostics::class);
        $report = $diagnostics->report();

        $this->assertCount(14, $report->checks);
        $this->assertSame($report->toLegacyChecks(), $diagnostics->run());
        $this->assertSame([
            'Application key',
            'Application URL',
            'Database migrations',
            'Debug mode',
            'Queue connection',
        ], array_map(
            fn ($check): string => $check->name,
            $report->forCategory(OperationalDiagnosticCategory::Runtime),
        ));
        $this->assertSame([
            'Storage directory',
            'Bootstrap cache',
        ], array_map(
            fn ($check): string => $check->name,
            $report->forCategory(OperationalDiagnosticCategory::Storage),
        ));
        $this->assertSame([
            'Database connection',
            'Email delivery',
            'External monitoring',
            'Pending queue state',
            'Failed queue jobs',
        ], array_map(
            fn ($check): string => $check->name,
            $report->forCategory(OperationalDiagnosticCategory::Connectivity),
        ));
        $this->assertSame([
            'Application services',
            'Automation timers',
        ], array_map(
            fn ($check): string => $check->name,
            $report->forCategory(OperationalDiagnosticCategory::Process),
        ));
    }
}
