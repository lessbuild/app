<?php

declare(strict_types=1);

namespace App\Queries\Security;

use App\Models\Project;
use App\Models\SecurityFinding;
use App\Models\SecurityScan;
use App\Services\Security\Scanners;
use Carbon\CarbonImmutable;

/** A project's Security at a glance: its score, open findings by severity and source, and each check's last scan. */
final class SecurityOverviewQuery
{
    /**
     * Create a new SecurityOverviewQuery instance.
     *
     * @param  Scanners  $scanners  The checks and what the plan includes.
     */
    public function __construct(private readonly Scanners $scanners) {}

    /**
     * Build the overview. The score starts at 100 and loses 25 per open critical finding, 10 per high, 3 per medium
     * and 1 per low, down to 0; the grade follows it (A from 90, B from 75, C from 60, D from 40, else F).
     *
     * @param  Project  $project
     * @return array{score: int, grade: string, bySeverity: array<string, int>, bySource: array<string, int>, checks: list<array{kind: string, label: string, included: bool, last: SecurityScan|null}>, recent: \Illuminate\Support\Collection<int, SecurityFinding>, intervalHours: int, trend: list<int>}
     */
    public function handle(Project $project): array
    {
        $open = SecurityFinding::query()->where('project_id', $project->id)->where('status', 'open');
        $bySeverity = [];
        foreach ((clone $open)->toBase()->selectRaw('severity, COUNT(*) AS total')->groupBy('severity')->get() as $row) {
            $bySeverity[(string) $row->severity] = (int) $row->total;
        }
        $bySource = [];
        foreach ((clone $open)->toBase()->selectRaw('source, COUNT(*) AS total')->groupBy('source')->get() as $row) {
            $bySource[(string) $row->source] = (int) $row->total;
        }
        $penalty = 0;
        foreach ($bySeverity as $severity => $count) {
            $penalty += (SecurityFinding::SEVERITIES[$severity]['weight'] ?? 0) * $count;
        }
        $score = max(0, 100 - $penalty);
        $checks = [];
        foreach ($this->scanners->all() as $kind => $scanner) {
            $checks[] = [
                'kind' => $kind,
                'label' => $scanner->label(),
                'included' => $this->scanners->included($project->account, $scanner),
                'last' => SecurityScan::query()->where('project_id', $project->id)->where('kind', $kind)->latest('id')->first(),
            ];
        }
        $rank = array_flip(array_keys(SecurityFinding::SEVERITIES));

        return [
            'score' => $score,
            'grade' => match (true) {
                $score >= 90 => 'A',
                $score >= 75 => 'B',
                $score >= 60 => 'C',
                $score >= 40 => 'D',
                default => 'F',
            },
            'bySeverity' => $bySeverity,
            'bySource' => $bySource,
            'checks' => $checks,
            'recent' => (clone $open)->orderByDesc('last_seen_at')->limit(200)->get()
                ->sortBy(fn (SecurityFinding $finding): int => $rank[$finding->severity] ?? 99)->take(8)->values()->toBase(),
            'intervalHours' => $this->scanners->intervalHours($project->account),
            'trend' => $this->trend($project),
        ];
    }

    /**
     * Work out the score at the end of each of the last seven days from when findings were first seen and resolved,
     * oldest first. Findings ignored since are left out throughout.
     *
     * @param  Project  $project
     * @return list<int>
     */
    public function trend(Project $project): array
    {
        $today = CarbonImmutable::today();
        $findings = SecurityFinding::query()->where('project_id', $project->id)->where('status', '!=', 'ignored')
            ->where('first_seen_at', '<=', $today->endOfDay())->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $today->subDays(6)->startOfDay()))
            ->get(['severity', 'first_seen_at', 'resolved_at']);

        return array_map(function (int $ago) use ($findings, $today): int {
            $end = $today->subDays($ago)->endOfDay();
            $penalty = $findings->filter(fn (SecurityFinding $finding): bool => $finding->first_seen_at->lessThanOrEqualTo($end) && ($finding->resolved_at === null || $finding->resolved_at->greaterThan($end)))
                ->sum(fn (SecurityFinding $finding): int => (int) (SecurityFinding::SEVERITIES[$finding->severity]['weight'] ?? 0));

            return max(0, 100 - (int) $penalty);
        }, range(6, 0));
    }
}
