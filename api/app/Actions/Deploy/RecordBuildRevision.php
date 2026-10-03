<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Events\Deploy\DeployStarted;
use App\Exceptions\StateConflict;
use App\Models\Build;
use App\Support\Deploy\ReleaseNotes;
use Illuminate\Support\Facades\DB;

final class RecordBuildRevision
{
    /**
     * Record the commit the script checked out (signed callback). A build asked for a specific commit must get exactly
     * that one. When the commit wasn't known until now, the deploy is announced as started on it. The commits since
     * the last live release are kept for the release notes.
     *
     * @param  Build  $build
     * @param  string  $revision
     * @param  string|null  $commitMessage
     * @param  string|null  $commits  one per line: short hash, author and subject, separated by the unit separator
     * @return void
     */
    public function handle(Build $build, string $revision, ?string $commitMessage, ?string $commits = null): void
    {
        $learned = DB::transaction(function () use ($build, $revision, $commitMessage, $commits): ?Build {
            $locked = Build::query()->lockForUpdate()->find($build->id);
            if ($locked === null || ! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
                return null;
            }
            $wasUnknown = $locked->revision === null;
            $revision = strtolower($revision);
            StateConflict::unless($locked->revision === null || hash_equals($locked->revision, $revision), 'The checked-out revision doesn’t match the requested one.');
            $message = $commitMessage === null ? null : trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $commitMessage) ?? '');
            $locked->forceFill(['revision' => $revision, 'commit_message' => $message === '' || $message === null ? null : mb_substr($message, 0, 500), 'last_heartbeat_at' => now()]);
            if ($commits !== null) {
                $locked->forceFill(['release_commits' => ReleaseNotes::parse($commits)]);
            }
            $locked->save();

            return $wasUnknown ? $locked : null;
        });
        // A deploy of the branch's latest commit only learns which one now; tell anyone following the commit.
        if ($learned !== null) {
            DeployStarted::dispatch($learned);
        }
    }
}
