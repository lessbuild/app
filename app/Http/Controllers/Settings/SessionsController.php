<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Identity\Actions\SignOutBrowsers;
use App\Domain\Identity\Models\SignInEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Queries\BrowserSessionsQuery;
use App\Domain\Identity\Queries\RecentSignInsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SessionsController
{
    public function index(Request $request, #[CurrentUser] User $user, BrowserSessionsQuery $sessions, RecentSignInsQuery $signIns): View
    {
        return view('settings.sessions', [
            'sessions' => $sessions->handle($user, $request->session()->getId()),
            'signIns' => $signIns->handle($user),
            'retentionDays' => SignInEvent::RETENTION_DAYS,
        ]);
    }

    public function destroy(Request $request, #[CurrentUser] User $user, SignOutBrowsers $signOut, string $session): RedirectResponse
    {
        $count = $signOut->handle($user, $request->session()->getId(), $session);

        return to_route('settings.sessions')->with('status', $count > 0 ? 'browser-signed-out' : 'browser-not-found');
    }

    public function destroyOthers(Request $request, #[CurrentUser] User $user, SignOutBrowsers $signOut): RedirectResponse
    {
        $signOut->handle($user, $request->session()->getId());

        return to_route('settings.sessions')->with('status', 'other-browsers-signed-out');
    }
}
