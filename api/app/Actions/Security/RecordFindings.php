<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Data\Security\Finding;
use App\Models\Project;
use App\Models\SecurityFinding;
use Illuminate\Support\Facades\DB;

final class RecordFindings
{
    /**
     * Bring a project's findings for one source and scope up to date with what a scan just saw: new problems are
     * added, ones seen again are refreshed (and reopened if they'd been resolved), and open ones that weren't seen are
     * resolved. Ignored findings stay ignored. Returns how many are open in the scope afterwards.
     *
     * @param  Project  $project
     * @param  string  $source  one of SecurityFinding::SOURCES' keys
     * @param  string  $scope  what was looked at, e.g. website:12
     * @param  list<Finding>  $findings
     * @return int
     */
    public function handle(Project $project, string $source, string $scope, array $findings): int
    {
        $now = now();

        return DB::transaction(function () use ($project, $source, $scope, $findings, $now): int {
            $seen = [];
            foreach ($findings as $finding) {
                $fingerprint = SecurityFinding::fingerprint($source, $scope, $finding->key);
                if (isset($seen[$fingerprint])) {
                    continue;
                }
                $seen[$fingerprint] = true;
                $row = SecurityFinding::query()->where('project_id', $project->id)->where('fingerprint', $fingerprint)->first() ?? new SecurityFinding;
                $row->forceFill([
                    'project_id' => $project->id, 'source' => $source, 'scope' => $scope, 'fingerprint' => $fingerprint,
                    'severity' => $finding->severity, 'title' => mb_substr($finding->title, 0, 255), 'detail' => $finding->detail,
                    'subject' => $finding->subject === null ? null : mb_substr($finding->subject, 0, 255), 'url' => $finding->url, 'fix' => $finding->fix,
                    'data' => $finding->data === [] ? null : $finding->data,
                    'status' => $row->status === 'ignored' ? 'ignored' : 'open', 'resolved_at' => null,
                    'first_seen_at' => $row->exists ? $row->first_seen_at : $now, 'last_seen_at' => $now,
                ])->save();
            }

            SecurityFinding::query()->where('project_id', $project->id)->where('source', $source)->where('scope', $scope)
                ->where('status', 'open')->whereNotIn('fingerprint', array_keys($seen))
                ->update(['status' => 'resolved', 'resolved_at' => $now, 'updated_at' => $now]);

            return SecurityFinding::query()->where('project_id', $project->id)->where('source', $source)->where('scope', $scope)->where('status', 'open')->count();
        });
    }
}
