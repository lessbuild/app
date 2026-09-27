<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectAlertRulesQuery;
use App\Services\Billing\Entitlements;
use App\Services\Monitoring\ServiceObjectiveReport;
use App\Services\Monitoring\ServiceObjectiveReportExporter;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

/** The objective's current report as CSV. */
final class ExportObjectiveController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $objective, ProjectAlertRulesQuery $rules, ServiceObjectiveReport $reports, ServiceObjectiveReportExporter $exporter, Entitlements $entitlements): Response
    {
        abort_unless($entitlements->for($project->account)->has('monitoring.slo_reports'), 403, __('SLO reports come with Monitoring Team and Scale.'));
        $target = $rules->objective($project, $objective);
        $report = $reports->forObjective($target);

        return response($exporter->csv($target, $report), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$exporter->filename($target, $report['until']).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
