<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Notifications\AccountSecurityNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

class ActivityRecorder
{
    /**
     * Append bounded metadata to a resource's activity history.
     *
     * @param  Model  $subject  A model exposing the events relation; it determines the recorded resource.
     * @param  int  $userId  The actor's user identifier.
     * @param  string  $category  The activity category, truncated to 255 characters.
     * @param  string  $message  The event description, truncated to 255 characters; callers must omit secrets.
     * @return Event The persisted activity event.
     */
    public function record(Model $subject, int $userId, string $category, string $message): Event
    {
        return $subject->events()->create([
            'user_id' => $userId,
            'category' => Str::limit($category, 255, ''),
            'event' => Str::limit($message, 255, ''),
        ]);
    }

    /**
     * Record an account-security event and attempt to notify its owner.
     *
     * @param  User  $user  The account whose own activity stream receives the event.
     * @param  string  $message  Metadata-only security description, bounded to 255 characters.
     * @return Event The persisted event; notification failures are reported without discarding it.
     */
    public function recordAccount(User $user, string $message): Event
    {
        $event = $user->accountEvents()->create([
            'user_id' => $user->id,
            'category' => 'account',
            'event' => Str::limit($message, 255, ''),
        ]);

        try {
            $user->notify(new AccountSecurityNotification($event->event));
        } catch (Throwable $exception) {
            report($exception);
        }

        return $event;
    }
}
