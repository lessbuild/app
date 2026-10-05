<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Build;

final class UpdateBuildNote
{
    /**
     * Save the team's note on a deploy (why it was done, what to watch), or clear it with an empty note.
     *
     * @param  Build  $build
     * @param  string|null  $note
     * @return Build
     */
    public function handle(Build $build, ?string $note): Build
    {
        $note = $note === null ? null : trim($note);
        $build->forceFill(['operator_note' => $note === '' ? null : $note])->save();

        return $build;
    }
}
