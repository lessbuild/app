<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
}
