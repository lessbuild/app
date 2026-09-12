<?php

namespace App\Actions\Project;

use App\Models\Project;

class DeleteProjectAction
{
    /**
     * Delete an authorized application and preserve the model's existing cascade behavior.
     *
     * @param  Project  $project  Application whose dependent project records are removed by the database.
     */
    public function handle(Project $project): void
    {
        $project->delete();
    }
}
