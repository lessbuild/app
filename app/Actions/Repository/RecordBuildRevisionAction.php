<?php

namespace App\Actions\Repository;

use App\Data\BuildRevisionResult;
use App\Models\Build;
use Illuminate\Support\Facades\DB;

class RecordBuildRevisionAction
{
    /**
     * Lock the running deployment, reject stale or conflicting revisions, and save normalized commit details with a fresh heartbeat.
     *
     * @param  Build  $build  Deployment receiving the remote revision callback.
     * @param  string  $revision  Reported source revision, normalized to lowercase before comparison.
     * @param  string|null  $commitMessage  Optional remote commit message to sanitize before storage.
     * @return string A BuildRevisionResult disposition: recorded, stale, or mismatch.
     */
    public function handle(Build $build, string $revision, ?string $commitMessage): string
    {
        return DB::transaction(function () use ($build, $revision, $commitMessage): string {
            $locked = Build::query()->lockForUpdate()->findOrFail($build->id);
            if (! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
                return BuildRevisionResult::STALE;
            }

            $revision = strtolower($revision);
            if ($locked->revision !== null && ! hash_equals($locked->revision, $revision)) {
                return BuildRevisionResult::MISMATCH;
            }

            $commitMessage = $this->normalizeMessage($commitMessage);
            $locked->update([
                'revision' => $revision,
                'commit_message' => $commitMessage,
                'last_heartbeat_at' => now(),
            ]);

            return BuildRevisionResult::RECORDED;
        });
    }

    /**
     * Remove control characters and surrounding whitespace, then bound the commit message to 500 characters.
     *
     * @param  string|null  $message  Optional untrusted commit message reported by the deployment process.
     * @return string|null The bounded commit message, or null when absent or empty after normalization.
     */
    private function normalizeMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $message = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message) ?? '');

        return $message === '' ? null : mb_substr($message, 0, 500);
    }
}
