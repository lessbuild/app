<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveIncidentPostmortem;
use App\Models\Incident;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateIncidentPostmortemController
{
    /**
     * Save the incident's post-mortem and return to the incident.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Incident  $incident
     * @param  SaveIncidentPostmortem  $save
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Incident $incident, SaveIncidentPostmortem $save): RedirectResponse
    {
        $rules = [];
        foreach (array_keys(SaveIncidentPostmortem::SECTIONS) as $key) {
            $rules[$key] = ['nullable', 'string', 'max:5000'];
        }
        /** @var array<string, string|null> $sections */
        $sections = $request->validate($rules);
        $save->handle($user, $incident, $sections);

        return to_route('monitoring.incidents.show', [$project, $incident->id])->with('status', __('Post-mortem saved.'));
    }
}
