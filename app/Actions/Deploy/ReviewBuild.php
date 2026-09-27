<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\StateConflict;
use App\Jobs\Deploy\PublishBuild;
use App\Models\Build;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ReviewBuild
{
    /** Approve a deploy that's waiting (it starts) or reject it, with an optional note. */
    public function handle(User $actor, Build $build, bool $approve, ?string $note = null): void
    {
        Gate::forUser($actor)->authorize('approve', $build);
        DB::transaction(function () use ($actor, $build, $approve, $note): void {
            $locked = Build::query()->lockForUpdate()->findOrFail($build->id);
            StateConflict::unless($locked->status === Build::STATUS_AWAITING_APPROVAL, __('This deploy isn’t waiting for approval.'));
            $note = $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 1000);
            $locked->forceFill($approve
                ? ['status' => Build::STATUS_QUEUED, 'approved_by' => $actor->id, 'approved_at' => now(), 'approval_note' => $note]
                : ['status' => Build::STATUS_REJECTED, 'rejected_by' => $actor->id, 'rejected_at' => now(), 'approval_note' => $note, 'finished_at' => now()])->save();
            if ($approve) {
                PublishBuild::dispatch($locked->id)->afterCommit();
            }
        });
    }
}
