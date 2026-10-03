<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\SaveAttackSettings;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateAttackSettingsController
{
    /**
     * Save the project's blocking settings and return to the attacks page.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveAttackSettings  $save
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, SaveAttackSettings $save): RedirectResponse
    {
        $data = $request->validate(['block_hours' => ['required', 'integer', 'between:1,720'], 'allowlist' => ['nullable', 'string', 'max:5000']]);
        $allowlist = array_values(array_filter(array_map(trim(...), preg_split('/[\s,]+/', (string) ($data['allowlist'] ?? '')) ?: [])));
        $save->handle($user, $project, $request->boolean('autoblock'), (int) $data['block_hours'], $allowlist);

        return to_route('security.attacks', $project)->with('status', __('Saved.'));
    }
}
