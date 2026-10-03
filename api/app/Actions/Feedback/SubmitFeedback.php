<?php

declare(strict_types=1);

namespace App\Actions\Feedback;

use App\Models\Feedback;
use App\Models\User;
use App\Notifications\NewFeedback;
use Illuminate\Support\Facades\Notification;

final class SubmitFeedback
{
    /**
     * Save feedback from a signed-in person and tell the platform admins about it.
     *
     * @param  User  $user
     * @param  string  $kind  one of Feedback::KINDS
     * @param  string  $message
     * @param  string|null  $page  the page it was sent from
     * @return Feedback
     */
    public function handle(User $user, string $kind, string $message, ?string $page): Feedback
    {
        $feedback = new Feedback;
        $feedback->forceFill(['user_id' => $user->id, 'account_id' => $user->current_account_id, 'kind' => $kind, 'message' => $message, 'page' => $page])->save();
        Notification::send(User::query()->where('is_platform_admin', true)->get(), new NewFeedback($feedback));

        return $feedback;
    }
}
