<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Events\BrowsersSignedOut;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\BrowserSessions;
use Illuminate\Support\Str;

final class SignOutBrowsers
{
    public function __construct(private readonly BrowserSessions $sessions) {}

    /**
     * End one browser session, or every session except the current one when $sessionId is null.
     * The single "remember me" token is rotated too, otherwise a signed-out browser would sign
     * straight back in from its cookie; the current session keeps working.
     */
    public function handle(User $user, string $currentSessionId, ?string $sessionId = null): int
    {
        if (! $this->sessions->available() || $sessionId === $currentSessionId) {
            return 0;
        }

        $query = $this->sessions->for($user)->where('id', '!=', $currentSessionId);
        if ($sessionId !== null) {
            $query->where('id', $sessionId);
        }
        $count = $query->delete();

        if ($count > 0) {
            $user->setRememberToken(Str::random(60));
            $user->save();
            BrowsersSignedOut::dispatch($user, $count);
        }

        return $count;
    }
}
