<?php

declare(strict_types=1);

namespace App\Data\Monitoring;

use App\Models\ServiceLevelObjective;

final readonly class ObjectiveSummary
{
    /**
     * Create a new ObjectiveSummary instance.
     *
     * A service level objective as lists show it, with how it's doing.
     *
     * @param  int  $id
     * @param  string  $name
     * @param  string  $projectId
     * @param  string  $project
     * @param  string  $environment
     * @param  string  $indicator  Such as "Availability", in the person's language.
     * @param  string  $target  The target percentage, such as "99.9".
     * @param  string  $scope  Which requests count, in the person's language.
     * @param  string  $window  Such as "30 days", in the person's language.
     * @param  bool  $enabled
     * @param  float|null  $compliance  Percentage of good requests, when there's data.
     * @param  float|null  $budgetRemaining  Percentage of the error budget left, when there's data.
     * @param  string  $status  healthy, warning, exhausted or no_data.
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $projectId,
        public string $project,
        public string $environment,
        public string $indicator,
        public string $target,
        public string $scope,
        public string $window,
        public bool $enabled,
        public ?float $compliance,
        public ?float $budgetRemaining,
        public string $status,
    ) {}

    /**
     * Describe an objective (with its environment and project loaded) and its report.
     *
     * @param  ServiceLevelObjective  $objective
     * @param  array{compliance: float|null, budget_remaining: float|null, status: string}  $report
     * @return self
     */
    public static function from(ServiceLevelObjective $objective, array $report): self
    {
        return new self(
            id: $objective->id,
            name: $objective->name,
            projectId: $objective->environment->project_id,
            project: $objective->environment->project->name,
            environment: $objective->environment->name,
            indicator: __($objective->indicatorLabel()),
            target: rtrim(rtrim(number_format((float) $objective->target, 3), '0'), '.'),
            scope: __($objective->scopeLabel()),
            window: __($objective->windowLabel()),
            enabled: (bool) $objective->enabled,
            compliance: $report['compliance'],
            budgetRemaining: $report['budget_remaining'],
            status: $report['status'],
        );
    }
}
