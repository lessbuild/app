<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Events\Users\BrowsersSignedOut;
use App\Models\User;
use App\Services\BrowserSessions;
use Illuminate\Support\Str;

final class SignOutBrowsers
{
    /**
     * Create a new SignOutBrowsers instance.
     *
     * Ends the person's other browser sessions.
     *
     * @param  BrowserSessions  $sessions  Reads and deletes sessions in the session store.
     */
    public function __construct(private readonly BrowserSessions $sessions) {}

    /**
     * End one browser session, or every session except the current one when $sessionId is null.
     * The single "remember me" token is rotated too, otherwise a signed-out browser would sign
     * straight back in from its cookie; the current session keeps working.
     *
     * @param  User  $user
     * @param  string  $currentSessionId
     * @param  string|null  $sessionId
     * @return int
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
