<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\PublishIncidentPostmortem;
use App\Models\Incident;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\StatusUpdate;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PublishIncidentPostmortemController
{
    /**
     * Publish the incident's post-mortem to one of the account's status pages and return to the incident.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Incident  $incident
     * @param  PublishIncidentPostmortem  $publish
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Incident $incident, PublishIncidentPostmortem $publish): RedirectResponse
    {
        $data = $request->validate([
            'status_page_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'severity' => ['required', Rule::in(StatusUpdate::SEVERITIES)],
        ]);
        $page = StatusPage::query()->where('account_id', $incident->account_id)->whereKey((int) $data['status_page_id'])->firstOrFail();
        $publish->handle($user, $incident, $page, (string) $data['title'], (string) $data['severity']);

        return to_route('monitoring.incidents.show', [$project, $incident->id])->with('status', __('Post-mortem published to :page.', ['page' => $page->name]));
    }
}
