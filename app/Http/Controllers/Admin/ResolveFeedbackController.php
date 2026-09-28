<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Feedback\ResolveFeedback;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ResolveFeedbackController
{
    /**
     * Mark feedback resolved, or open it again, and go back to the list.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $feedback
     * @param  ResolveFeedback  $resolve
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, string $feedback, ResolveFeedback $resolve): RedirectResponse
    {
        $resolve->handle($user, Feedback::query()->findOrFail((int) $feedback), $request->boolean('resolved'));

        return back()->with('status', $request->boolean('resolved') ? __('Marked resolved.') : __('Opened again.'));
    }
}
