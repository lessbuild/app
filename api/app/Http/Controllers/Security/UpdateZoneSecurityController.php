<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\SetZoneSecurity;
use App\Models\Project;
use App\Models\SecurityZone;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UpdateZoneSecurityController
{
    /**
     * Save a zone's security settings and send the app back to the firewall page.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $zone
     * @param  SetZoneSecurity  $set
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $zone, SetZoneSecurity $set): JsonResponse
    {
        $data = $request->validate(['security_level' => ['required', Rule::in(array_keys(SecurityZone::LEVELS))]]);
        $set->handle($user, $project, $zone, $data['security_level'], $request->boolean('bot_fight_mode'), $request->boolean('under_attack'));

        return response()->json(['redirect' => route('security.firewall', $project, false), 'message' => $request->boolean('under_attack') ? __('Under attack mode is on: every visitor gets a check first.') : __('Saved. Cloudflare applies it within a minute.')]);
    }
}
