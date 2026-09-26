<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Enums\ProjectResourceAccessPurpose;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\MappedProjectResourceAccess;
use App\Modules\Monitor\Models\AlertDelivery;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\AuditLog;
use App\Modules\Monitor\Models\Deployment;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\IngestToken;
use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\Release;
use App\Modules\Monitor\Models\StatusPage;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/** Adds canonical project access to existing Monitor workspace and role checks. */
final class MonitorProjectAccess
{
    public function __construct(
        private readonly MappedProjectResourceAccess $access,
        private readonly ProductAuthentication $authentication,
    ) {}

    public function application(Authenticatable $principal, Application $application, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive): bool
    {
        return $this->access->allows($principal, 'monitor', 'application', $application->getKey(), 'workspace', $application->workspace_id, $purpose);
    }

    public function environment(Authenticatable $principal, Environment $environment, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive): bool
    {
        $application = $purpose === ProjectResourceAccessPurpose::Interactive ? $environment->application : $environment->application()->withTrashed()->first();

        return $application !== null && $this->application($principal, $application, $purpose)
            && $this->access->allows($principal, 'monitor', 'environment', $environment->getKey(), 'workspace', $application->workspace_id, $purpose);
    }

    public function constrain(Builder $query, Authenticatable $principal, Workspace $workspace, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive): void
    {
        if (! $this->authentication->usesCoreAuthority('monitor')) {
            return;
        }
        $model = $query->getModel();
        if ($model instanceof Application) {
            $query->where($model->qualifyColumn('workspace_id'), $workspace->getKey());
            $this->excludeDenied($query, $principal, $workspace, 'application', $model->qualifyColumn('id'), $purpose);

            return;
        }
        if ($model instanceof Environment) {
            $query->whereIn($model->qualifyColumn('application_id'), Application::withTrashed()->visibleTo($principal, $workspace, $purpose)->select('id'));
            $this->excludeDenied($query, $principal, $workspace, 'environment', $model->qualifyColumn('id'), $purpose);

            return;
        }
        if ($model instanceof Issue || $model instanceof Release) {
            $query->whereIn($model->qualifyColumn('application_id'), Application::withTrashed()->visibleTo($principal, $workspace, $purpose)->select('id'));
            if ($model instanceof Issue) {
                $query->where(fn (Builder $issues) => $issues->whereNull('environment_id')
                    ->orWhereIn('environment_id', Environment::withTrashed()->visibleTo($principal, $workspace, $purpose)
                        ->whereColumn('application_id', $model->qualifyColumn('application_id'))->select('id')));
            }

            return;
        }
        if ($model instanceof StatusPage) {
            $query->where($model->qualifyColumn('workspace_id'), $workspace->getKey())
                ->whereDoesntHave('components', fn (Builder $components) => $components
                    ->whereNotIn('monitor_id', Monitor::withTrashed()->visibleTo($principal, $workspace, $purpose)->select('id')));

            return;
        }
        if ($model instanceof AuditLog) {
            $query->where($model->qualifyColumn('workspace_id'), $workspace->getKey());
            foreach ([Application::class, Environment::class, IngestToken::class, AlertRule::class, Monitor::class] as $subjectClass) {
                $subject = new $subjectClass;
                $subjectTypes = [$subject->getMorphClass(), 'App\\Models\\'.class_basename($subjectClass)];
                $subjects = $subjectClass::query()->visibleTo($principal, $workspace, $purpose);
                if (in_array($subjectClass, [Application::class, Environment::class, AlertRule::class, Monitor::class], true)) {
                    $subjects->withTrashed();
                }
                $query->where(fn (Builder $logs) => $logs->whereNull('subject_type')
                    ->orWhereNotIn('subject_type', $subjectTypes)
                    ->orWhereIn('subject_id', $subjects->select('id')));
            }

            return;
        }
        if ($model instanceof Incident) {
            $rules = AlertRule::withTrashed()->visibleTo($principal, $workspace, $purpose)->select('id');
            $monitors = Monitor::withTrashed()->visibleTo($principal, $workspace, $purpose)->select('id');
            $query->where(fn (Builder $sources) => $sources->whereNotNull('alert_rule_id')->orWhereNotNull('monitor_id'))
                ->where(fn (Builder $sources) => $sources->whereNull('alert_rule_id')->orWhereIn('alert_rule_id', $rules))
                ->where(fn (Builder $sources) => $sources->whereNull('monitor_id')->orWhereIn('monitor_id', $monitors));

            return;
        }
        if ($model instanceof AlertDelivery) {
            $query->where($model->qualifyColumn('workspace_id'), $workspace->getKey())
                ->where(fn (Builder $deliveries) => $deliveries->whereNull('incident_id')
                    ->orWhereIn('incident_id', Incident::query()->visibleTo($principal, $workspace, $purpose)->select('id')));

            return;
        }

        if ($model instanceof Deployment) {
            $query->whereHas('release', fn (Builder $releases) => $releases->visibleTo($principal, $workspace, $purpose)
                ->whereIn('application_id', $this->environments($purpose)
                    ->whereColumn('id', $model->qualifyColumn('environment_id'))->select('application_id')));
        }

        $query->whereIn($model->qualifyColumn('environment_id'), $this->environments($purpose)->visibleTo($principal, $workspace, $purpose)->select('id'));
    }

    /** @return Builder<Environment> */
    private function environments(ProjectResourceAccessPurpose $purpose): Builder
    {
        return Environment::query()->when($purpose === ProjectResourceAccessPurpose::HistoricalExport, fn (Builder $query) => $query->withTrashed());
    }

    private function excludeDenied(Builder $query, Authenticatable $principal, Workspace $workspace, string $type, string $column, ProjectResourceAccessPurpose $purpose): void
    {
        $applications = Application::withTrashed()->where('workspace_id', $workspace->getKey())->select('id');
        $candidateIds = $type === 'application'
            ? $applications->pluck('id')->all()
            : Environment::withTrashed()->whereIn('application_id', $applications)->pluck('id')->all();
        $denied = $this->access->deniedResourceIds($principal, 'monitor', $type, 'workspace', $workspace->getKey(), $candidateIds, $purpose);
        if ($denied === null) {
            $query->whereRaw('1 = 0');
        } elseif ($denied !== []) {
            $query->whereNotIn($column, $denied);
        }
    }
}
