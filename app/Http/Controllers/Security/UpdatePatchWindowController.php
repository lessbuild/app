<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\SetPatchWindow;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdatePatchWindowController
{
    /**
     * Save a server's update window, or install its updates now, and return to Security's servers.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $server
     * @param  SetPatchWindow  $set
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, int $server, SetPatchWindow $set): RedirectResponse
    {
        $data = $request->validate(['patch_day' => ['nullable', 'integer', 'between:0,6'], 'patch_hour' => ['nullable', 'integer', 'between:0,23'], 'now' => ['nullable', 'boolean']]);
        $now = $request->boolean('now');
        $set->handle($user, $project, $server, isset($data['patch_day']) ? (int) $data['patch_day'] : null, (int) ($data['patch_hour'] ?? 3), $request->boolean('patch_reboot'), $now);

        return to_route('security.servers', $project)->with('status', $now ? __('Installing updates. The server’s findings update at the next check.') : __('Update window saved.'));
    }
}
