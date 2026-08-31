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
    public function record(Model $subject, int $userId, string $category, string $message): Event
    {
        return $subject->events()->create([
            'user_id' => $userId,
            'category' => Str::limit($category, 255, ''),
            'event' => Str::limit($message, 255, ''),
        ]);
    }

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
