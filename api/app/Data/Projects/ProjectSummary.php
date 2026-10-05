<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Models\Project;

final readonly class ProjectSummary
{
    /**
     * Create a new ProjectSummary instance.
     *
     * A project as its pages show it.
     *
     * @param  string  $id
     * @param  string  $name
     * @param  string|null  $description
     * @param  string  $accountName
     * @param  bool  $isSample  Whether it's the sample project, with made-up data.
     * @param  bool  $checklistDismissed  Whether the setup checklist was hidden from the overview.
     * @param  string  $createdAt  ISO 8601.
     * @param  string|null  $archivedAt  When it was archived (ISO 8601), or null while it's in use.
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public string $accountName,
        public bool $isSample,
        public bool $checklistDismissed,
        public string $createdAt,
        public ?string $archivedAt = null,
    ) {}

    /**
     * Describe a project.
     *
     * @param  Project  $project
     * @return self
     */
    public static function from(Project $project): self
    {
        return new self(
            $project->id, $project->name, $project->description, $project->account->name, (bool) $project->is_sample,
            $project->checklist_dismissed_at !== null, (string) $project->created_at?->toIso8601String(), $project->archived_at?->toIso8601String(),
        );
    }
}
