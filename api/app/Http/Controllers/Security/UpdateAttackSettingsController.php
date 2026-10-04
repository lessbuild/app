<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\SaveAttackSettings;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateAttackSettingsController
{
    /**
     * Save the project's blocking settings and send the app back to the attacks page.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveAttackSettings  $save
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, SaveAttackSettings $save): JsonResponse
    {
        $data = $request->validate(['block_hours' => ['required', 'integer', 'between:1,720'], 'allowlist' => ['nullable', 'string', 'max:5000']]);
        $allowlist = array_values(array_filter(array_map(trim(...), preg_split('/[\s,]+/', (string) ($data['allowlist'] ?? '')) ?: [])));
        $save->handle($user, $project, $request->boolean('autoblock'), (int) $data['block_hours'], $allowlist);

        return response()->json(['redirect' => route('security.attacks', $project, false), 'message' => __('Saved.')]);
    }
}
