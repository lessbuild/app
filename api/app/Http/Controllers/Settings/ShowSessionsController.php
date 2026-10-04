<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Data\Users\SignInSummary;
use App\Models\SignInEvent;
use App\Models\User;
use App\Queries\Users\BrowserSessionsQuery;
use App\Queries\Users\RecentSignInsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /api/app/settings/sessions`. */
final class ShowSessionsController
{
    /**
     * Return the browsers the person is signed in on (this one marked) and their recent sign-ins.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  BrowserSessionsQuery  $sessions
     * @param  RecentSignInsQuery  $signIns
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, BrowserSessionsQuery $sessions, RecentSignInsQuery $signIns): JsonResponse
    {
        return response()->json([
            'sessions' => $sessions->handle($user, $request->session()->getId()),
            'signIns' => array_map(fn (SignInSummary $signIn): array => [
                'succeeded' => $signIn->succeeded,
                'method' => $signIn->method?->label(),
                'twoFactor' => $signIn->twoFactor,
                'device' => $signIn->device,
                'ipAddress' => $signIn->ipAddress,
                'at' => $signIn->at->toIso8601String(),
            ], $signIns->handle($user)),
            'retentionDays' => SignInEvent::RETENTION_DAYS,
        ]);
    }
}
