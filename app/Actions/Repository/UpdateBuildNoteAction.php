<?php

namespace App\Actions\Repository;

use App\Models\Build;
use App\Models\User;
use App\Services\ActivityRecorder;

class UpdateBuildNoteAction
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    /**
     * Update an operator note and record metadata-only activity when its value changes.
     *
     * @param  Build  $build  Authorized build whose operator note is being changed.
     * @param  User  $actor  User attributed to the note change.
     * @param  string|null  $note  Validated and normalized operator note.
     * @return bool Whether the persisted note changed.
     */
    public function handle(Build $build, User $actor, ?string $note): bool
    {
        if ($build->operator_note === $note) {
            return false;
        }

        $build->update(['operator_note' => $note]);
        $this->activity->record(
            $build,
            $actor->id,
            'deployment',
            $note === null ? 'Deployment note was cleared.' : 'Deployment note was updated.',
        );

        return true;
    }
}
