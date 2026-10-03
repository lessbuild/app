<?php

declare(strict_types=1);

namespace App\Http\Controllers\Feedback;

use App\Actions\Feedback\SubmitFeedback;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StoreFeedbackController
{
    /**
     * Send feedback from the in-app form and go back to the page it came from.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  SubmitFeedback  $submit
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, SubmitFeedback $submit): RedirectResponse
    {
        // Prefixed names keep the form's errors apart from those of the page it opened over.
        $data = $request->validate([
            'feedback_kind' => ['required', 'string', Rule::in(array_keys(Feedback::KINDS))],
            'feedback_message' => ['required', 'string', 'min:3', 'max:5000'],
            'feedback_page' => ['nullable', 'string', 'max:2048'],
        ], attributes: ['feedback_kind' => __('kind'), 'feedback_message' => __('message')]);
        $page = isset($data['feedback_page']) && str_starts_with((string) $data['feedback_page'], rtrim((string) config('app.url'), '/').'/') ? (string) $data['feedback_page'] : null;
        $submit->handle($user, (string) $data['feedback_kind'], (string) $data['feedback_message'], $page);

        return back()->with('feedback', __('Thanks — your feedback has been sent.'));
    }
}
