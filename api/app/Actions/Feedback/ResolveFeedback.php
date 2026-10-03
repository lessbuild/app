<?php

declare(strict_types=1);

namespace App\Actions\Feedback;

use App\Models\Feedback;
use App\Models\User;

final class ResolveFeedback
{
    /**
     * Mark feedback resolved by an admin, or open it again.
     *
     * @param  User  $admin
     * @param  Feedback  $feedback
     * @param  bool  $resolved
     * @return void
     */
    public function handle(User $admin, Feedback $feedback, bool $resolved): void
    {
        $feedback->forceFill($resolved ? ['resolved_by' => $admin->id, 'resolved_at' => now()] : ['resolved_by' => null, 'resolved_at' => null])->save();
    }
}
