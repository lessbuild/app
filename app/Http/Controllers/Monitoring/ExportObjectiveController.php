<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Monitoring\ServiceObjectiveReport;
use App\Services\Monitoring\ServiceObjectiveReportExporter;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

/** The objective's current report as CSV. */
final class ExportObjectiveController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, ServiceLevelObjective $objective, ServiceObjectiveReport $reports, ServiceObjectiveReportExporter $exporter, Entitlements $entitlements): Response
    {
        $report = $reports->forObjective($objective);

        return response($exporter->csv($objective, $report), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$exporter->filename($objective, $report['until']).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
