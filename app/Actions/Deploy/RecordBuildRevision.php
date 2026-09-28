<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\StateConflict;
use App\Models\Build;
use Illuminate\Support\Facades\DB;

final class RecordBuildRevision
{
    /**
     * The commit the script checked out (signed callback). A build asked for a specific commit must get exactly that one.
     *
     * @param  Build  $build
     * @param  string  $revision
     * @param  string|null  $commitMessage
     * @return void
     */
    public function handle(Build $build, string $revision, ?string $commitMessage): void
    {
        DB::transaction(function () use ($build, $revision, $commitMessage): void {
            $locked = Build::query()->lockForUpdate()->find($build->id);
            if ($locked === null || ! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
                return;
            }
            $revision = strtolower($revision);
            StateConflict::unless($locked->revision === null || hash_equals($locked->revision, $revision), 'The checked-out revision doesn’t match the requested one.');
            $message = $commitMessage === null ? null : trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $commitMessage) ?? '');
            $locked->forceFill(['revision' => $revision, 'commit_message' => $message === '' || $message === null ? null : mb_substr($message, 0, 500), 'last_heartbeat_at' => now()])->save();
        });
    }
}
