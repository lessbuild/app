<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\Incident;
use App\Models\IncidentActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SaveIncidentPostmortem
{
    /**
     * The post-mortem's sections, in order, with their headings.
     *
     * @var array<string, string>
     */
    public const SECTIONS = [
        'summary' => 'Summary',
        'impact' => 'Impact',
        'root_cause' => 'Root cause',
        'resolution' => 'How it was resolved',
        'follow_ups' => 'Follow-up actions',
    ];

    /**
     * Save the incident's post-mortem and note it on the timeline. Empty sections are left out.
     *
     * @param  User  $actor
     * @param  Incident  $incident
     * @param  array<string, string|null>  $sections  keyed by SECTIONS
     * @return void
     */
    public function handle(User $actor, Incident $incident, array $sections): void
    {
        Gate::forUser($actor)->authorize('update', $incident);
        $postmortem = [];
        foreach (array_keys(self::SECTIONS) as $key) {
            $text = trim((string) ($sections[$key] ?? ''));
            if ($text !== '') {
                $postmortem[$key] = mb_substr($text, 0, 5000);
            }
        }
        DB::transaction(function () use ($actor, $incident, $postmortem): void {
            $incident->forceFill(['postmortem' => $postmortem === [] ? null : $postmortem])->save();
            (new IncidentActivity)->forceFill(['incident_id' => $incident->id, 'actor_id' => $actor->id, 'action' => 'postmortem_saved'])->save();
        });
    }
}
