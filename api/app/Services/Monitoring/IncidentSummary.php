<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Enums\AlertMetric;
use App\Models\Deployment;
use App\Models\Incident;
use App\Models\IncidentActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Drafts an incident's post-mortem from what BuildPusher already knows: when it opened and closed, what the check or
 * rule saw, who responded, the notes on the timeline, and the deploys shortly before it. People edit the draft; it
 * never guesses a root cause, only lists leads.
 */
final class IncidentSummary
{
    /**
     * How far before an incident a deploy counts as a lead, in minutes.
     *
     * @var int
     */
    public const DEPLOY_LEAD_MINUTES = 120;

    /**
     * Draft each post-mortem section (summary, impact, root cause, resolution and follow-ups).
     *
     * @param  Incident  $incident
     * @return array<string, string>
     */
    public function draft(Incident $incident): array
    {
        $incident->loadMissing(['monitor', 'alertRule', 'activities.actor', 'acknowledgedBy']);
        $opened = $incident->opened_at;
        $closed = $incident->resolved_at;
        $when = fn (CarbonInterface $time): string => $time->utc()->isoFormat('D MMM YYYY, HH:mm').' UTC';
        $length = fn (CarbonInterface $from, CarbonInterface $to): string => $from->diffForHumans($to, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]);

        $summary = [__(':title. It opened on :opened', ['title' => rtrim($incident->title, '.'), 'opened' => $when($opened)])
            .($closed === null ? __(' and is still open.') : __(' and closed :length later (:how).', ['length' => $length($opened, $closed), 'how' => mb_strtolower(__($incident->statusLabel()))]))];
        if ($incident->acknowledged_at !== null) {
            $summary[] = __(':who acknowledged it after :length.', ['who' => $incident->acknowledgedBy->name ?? __('Someone'), 'length' => $length($opened, $incident->acknowledged_at)]);
        }

        $deploys = $this->deploysBefore($incident);
        $leads = array_map(fn (Deployment $deploy): string => '- '.__('Release :version:commit went live :length before it opened.', [
            'version' => $deploy->release->version ?? '?',
            'commit' => $deploy->commit_sha ? ' ('.substr($deploy->commit_sha, 0, 7).')' : '',
            'length' => $length($deploy->deployed_at, $opened),
        ]), $deploys);
        $notes = $incident->activities->where('action', 'note')->filter(fn (IncidentActivity $activity): bool => trim((string) $activity->note) !== '');

        $timeline = $incident->activities->sortBy('id')->map(fn (IncidentActivity $activity): string => '- '.$activity->created_at?->utc()->format('H:i').' '.__($activity->label())
            .($activity->actor ? ' ('.$activity->actor->name.')' : '')
            .(trim((string) $activity->note) !== '' ? ': '.trim((string) $activity->note) : ''))->values()->all();

        $followUps = [];
        if ($incident->acknowledged_at === null) {
            $followUps[] = '- '.__('Nobody acknowledged it: check the alert reaches whoever is on call.');
        } elseif ($opened->diffInMinutes($incident->acknowledged_at) > 15) {
            $followUps[] = '- '.__('It took over 15 minutes to acknowledge: check escalation steps and on-call cover.');
        }
        if ($deploys !== []) {
            $followUps[] = '- '.__('If a release caused it, add a check or test that would have caught it before deploying.');
        }

        return array_filter([
            'summary' => implode(' ', $summary),
            'impact' => $this->impact($incident, $closed !== null ? $length($opened, $closed) : null),
            'root_cause' => $leads === [] && $notes->isEmpty()
                ? (string) __('To confirm. No deploys went live in the two hours before it opened.')
                : implode("\n", [(string) __('To confirm. Leads:'), ...$leads, ...$notes->map(fn (IncidentActivity $note): string => '- '.__('Note from :who: :note', ['who' => $note->actor->name ?? __('the team'), 'note' => trim((string) $note->note)]))->all()]),
            'resolution' => $timeline === [] ? '' : implode("\n", [(string) __('Timeline (UTC):'), ...$timeline]),
            'follow_ups' => implode("\n", $followUps),
        ], fn (string $text): bool => $text !== '');
    }

    /**
     * Describe what the check or rule saw when the incident opened.
     *
     * @param  Incident  $incident
     * @param  string|null  $length  how long it lasted, when closed
     * @return string
     */
    private function impact(Incident $incident, ?string $length): string
    {
        $seen = $incident->opening_observation;
        $for = $length === null ? '' : ' '.__('for :length', ['length' => $length]);
        if ($incident->monitor_id !== null) {
            $name = (string) ($incident->rule_snapshot['name'] ?? $incident->monitor->name ?? __('The monitor'));
            $detail = match (true) {
                isset($seen['http_status']) => __('it answered HTTP :status', ['status' => $seen['http_status']]),
                isset($seen['reason']) => str_replace('_', ' ', (string) $seen['reason']),
                default => __('it failed its checks'),
            };

            return (string) __(':name was down:for; :detail.', ['name' => $name, 'for' => $for, 'detail' => $detail]);
        }
        $metric = AlertMetric::tryFrom((string) ($incident->rule_snapshot['metric'] ?? ''));
        $threshold = $incident->rule_snapshot['threshold'] ?? null;

        return (string) __(':metric reached :value (the rule alerts at :threshold, over :window minutes):for.', [
            'metric' => $metric !== null ? __($metric->label()) : __('The measured value'),
            'value' => is_numeric($seen['value'] ?? null) ? rtrim(rtrim(number_format((float) $seen['value'], 2, '.', ''), '0'), '.') : '?',
            'threshold' => is_numeric($threshold) ? rtrim(rtrim(number_format((float) $threshold, 2, '.', ''), '0'), '.') : '?',
            'window' => (int) ($incident->rule_snapshot['window_minutes'] ?? 0),
            'for' => $for,
        ]);
    }

    /**
     * Find the deploys to the incident's environment in the two hours before it opened, newest first.
     *
     * @param  Incident  $incident
     * @return list<Deployment>
     */
    private function deploysBefore(Incident $incident): array
    {
        $environment = $incident->monitor->environment_id ?? $incident->alertRule->environment_id ?? null;
        if ($environment === null) {
            return [];
        }
        $opened = CarbonImmutable::parse($incident->opened_at);

        return array_values(Deployment::query()->with('release')->where('environment_id', $environment)
            ->whereBetween('deployed_at', [$opened->subMinutes(self::DEPLOY_LEAD_MINUTES), $opened])
            ->latest('deployed_at')->limit(5)->get()->all());
    }
}
