<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Where a form started from the setup guide goes back to. Only a project's own setup guide is accepted, so the value
 * a form posts can't send anyone anywhere else.
 */
final class SetupGuideReturn
{
    /**
     * Get the setup guide address the form asked to return to, or null to use the usual page.
     *
     * @param  Request  $request
     * @return string|null
     */
    public static function from(Request $request): ?string
    {
        $path = $request->input('_return');

        return is_string($path) && preg_match('#\A/projects/[0-9a-z]{26}/setup\z#', $path) === 1 ? url($path) : null;
    }
}
