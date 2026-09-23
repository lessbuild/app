<?php

namespace Tests\Unit;

use App\Modules\Deployer\Data\OperationalDiagnosticCheck;
use App\Modules\Deployer\Data\OperationalDiagnosticReport;
use App\Modules\Deployer\Enums\OperationalDiagnosticCategory;
use PHPUnit\Framework\TestCase;

class OperationalDiagnosticReportTest extends TestCase
{
    public function test_report_counts_checks_and_selects_a_category_without_reordering(): void
    {
        $runtime = new OperationalDiagnosticCheck(
            'Application URL',
            OperationalDiagnosticCategory::Runtime,
            true,
            'Valid HTTP(S) URL',
        );
        $storage = new OperationalDiagnosticCheck(
            'Storage directory',
            OperationalDiagnosticCategory::Storage,
            false,
            'Missing or not writable',
        );
        $report = new OperationalDiagnosticReport([$runtime, $storage]);

        $this->assertFalse($report->passed());
        $this->assertSame(1, $report->passedCount());
        $this->assertSame([$storage], $report->forCategory(OperationalDiagnosticCategory::Storage));
        $this->assertSame([
            ['name' => 'Application URL', 'passed' => true, 'detail' => 'Valid HTTP(S) URL'],
            ['name' => 'Storage directory', 'passed' => false, 'detail' => 'Missing or not writable'],
        ], $report->toLegacyChecks());
    }
}
