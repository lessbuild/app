<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\Incident;
use App\Models\IncidentActivity;
use App\Models\StatusPage;
use App\Models\StatusUpdate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class PublishIncidentPostmortem
{
    /**
     * Publish a resolved incident's post-mortem to a status page as a resolved incident report, or update the report
     * it was published as. Subscribers aren't emailed: the incident is over.
     *
     * @param  User  $actor
     * @param  Incident  $incident
     * @param  StatusPage  $page
     * @param  string  $title  the public title
     * @param  string  $severity  one of StatusUpdate::SEVERITIES
     * @return StatusUpdate
     */
    public function handle(User $actor, Incident $incident, StatusPage $page, string $title, string $severity): StatusUpdate
    {
        Gate::forUser($actor)->authorize('update', $incident);
        Gate::forUser($actor)->authorize('update', $page);
        if ($page->account_id !== $incident->account_id) {
            throw ValidationException::withMessages(['status_page_id' => __('Choose one of this account’s status pages.')]);
        }
        $postmortem = $incident->postmortem ?? [];
        if ($incident->resolved_at === null || ($postmortem['summary'] ?? '') === '') {
            throw ValidationException::withMessages(['status_page_id' => __('Publish once the incident is resolved and the post-mortem has a summary.')]);
        }

        return DB::transaction(function () use ($actor, $incident, $page, $title, $severity, $postmortem): StatusUpdate {
            $update = StatusUpdate::query()->whereKey($incident->postmortem_status_update_id)->where('status_page_id', $page->id)->first() ?? new StatusUpdate;
            $update->forceFill([
                'status_page_id' => $page->id, 'created_by' => $update->created_by ?? $actor->id,
                'kind' => 'incident', 'status' => 'resolved', 'severity' => in_array($severity, StatusUpdate::SEVERITIES, true) ? $severity : 'minor',
                'title' => mb_substr(trim($title), 0, 200),
                'message' => trim($postmortem['summary'].(isset($postmortem['impact']) ? "\n\n".$postmortem['impact'] : '')),
                'root_cause' => $postmortem['root_cause'] ?? null, 'remediation' => $postmortem['resolution'] ?? null, 'follow_up' => $postmortem['follow_ups'] ?? null,
                'starts_at' => $incident->opened_at, 'ends_at' => $incident->resolved_at, 'resolved_at' => $incident->resolved_at,
            ])->save();
            $incident->forceFill(['postmortem_status_update_id' => $update->id])->save();
            (new IncidentActivity)->forceFill(['incident_id' => $incident->id, 'actor_id' => $actor->id, 'action' => 'postmortem_published', 'metadata' => ['status_page_id' => $page->id]])->save();

            return $update;
        });
    }
}
