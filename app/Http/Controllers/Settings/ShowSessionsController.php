<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Models\SignInEvent;
use App\Models\User;
use App\Queries\Users\BrowserSessionsQuery;
use App\Queries\Users\RecentSignInsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowSessionsController
{
    /**
     * The sessions page: signed-in browsers and recent sign-ins.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  BrowserSessionsQuery  $sessions
     * @param  RecentSignInsQuery  $signIns
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, BrowserSessionsQuery $sessions, RecentSignInsQuery $signIns): View
    {
        return view('settings.sessions', [
            'sessions' => $sessions->handle($user, $request->session()->getId()),
            'signIns' => $signIns->handle($user),
            'retentionDays' => SignInEvent::RETENTION_DAYS,
        ]);
    }
}
