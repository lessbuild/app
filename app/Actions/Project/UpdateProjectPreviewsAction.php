<?php

namespace App\Actions\Project;

use App\Models\Project;
use App\Services\Entitlements;

class UpdateProjectPreviewsAction
{
    public function __construct(
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * Save preview settings after rechecking the feature entitlement at the application boundary.
     *
     * @param  Project  $project  Application whose preview settings are being changed.
     * @param  array<string, mixed>  $attributes  Validated and normalized preview settings.
     */
    public function handle(Project $project, array $attributes): void
    {
        if (($attributes['preview_enabled'] ?? false) === true) {
            $this->entitlements->enforce($project->organization, 'previews');
        }

        $project->update($attributes);
    }
}
