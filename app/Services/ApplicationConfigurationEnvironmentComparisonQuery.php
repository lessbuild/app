<?php

namespace App\Services;

use App\Data\ApplicationEnvironmentComparison;
use App\Data\ApplicationEnvironmentOverview;
use App\Models\Project;

class ApplicationConfigurationEnvironmentComparisonQuery
{
    /**
     * Reuse the secret-safe environment reader while keeping comparison formatting outside the controller.
     *
     * @param  ApplicationConfigurationEnvironmentOverviewQuery  $environments  Loads only project-scoped recorded metadata.
     */
    public function __construct(private readonly ApplicationConfigurationEnvironmentOverviewQuery $environments) {}

    /**
     * Compare two recorded environments without querying provider state.
     *
     * @return ApplicationEnvironmentComparison|null Safe comparison, or null when an environment is not in the project.
     */
    public function for(Project $project, int $fromId, int $toId): ?ApplicationEnvironmentComparison
    {
        $pair = $this->environments->pair($project, $fromId, $toId);
        if ($pair === null) {
            return null;
        }

        $differences = [];
        foreach ($this->fields($pair['from'], $pair['to']) as $field => [$from, $to]) {
            if ($from !== $to) {
                $differences[] = ['field' => $field, 'from' => $from, 'to' => $to];
            }
        }

        return new ApplicationEnvironmentComparison($pair['from'], $pair['to'], $differences);
    }

    /**
     * Select only safe recorded fields for comparison; secret values and executable content never enter this map.
     *
     * @return array<string, array{0: string, 1: string}> Comparable display fields.
     */
    private function fields(ApplicationEnvironmentOverview $from, ApplicationEnvironmentOverview $to): array
    {
        return [
            'Environment type' => [$from->type, $to->type],
            'Recorded status' => [$from->status, $to->status],
            'Branch' => [$from->branch, $to->branch],
            'Runtime' => [$from->runtimeType, $to->runtimeType],
            'Protection' => [$from->isProtected ? 'protected' : 'unprotected', $to->isProtected ? 'protected' : 'unprotected'],
            'Server' => [$this->dependencyValue($from, 'server'), $this->dependencyValue($to, 'server')],
            'Website' => [$this->dependencyValue($from, 'website'), $this->dependencyValue($to, 'website')],
            'Repository' => [$this->dependencyValue($from, 'repository'), $this->dependencyValue($to, 'repository')],
            'Processes' => [$this->dependencyValue($from, 'process'), $this->dependencyValue($to, 'process')],
            'Resources' => [$this->dependencyValue($from, 'resource'), $this->dependencyValue($to, 'resource')],
            'Variables' => [$this->dependencyValue($from, 'variables'), $this->dependencyValue($to, 'variables')],
        ];
    }

    /**
     * Render one or more non-secret dependency descriptors without commands or configuration values.
     */
    private function dependencyValue(ApplicationEnvironmentOverview $overview, string $kind): string
    {
        return collect($overview->dependencies)
            ->where('kind', $kind)
            ->map(fn (array $dependency): string => $dependency['name'].' · '.$dependency['status'].' · '.$dependency['detail'])
            ->join('; ') ?: 'Not recorded';
    }
}
