<?php

declare(strict_types=1);

namespace App\Http\Controllers\Feedback;

use App\Actions\Feedback\SubmitFeedback;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** `POST /api/app/feedback`. */
final class StoreFeedbackController
{
    /**
     * Send feedback from anywhere in the app (with the page it was sent from, when that's one of ours) to the admins.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  SubmitFeedback  $submit
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, SubmitFeedback $submit): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in(array_keys(Feedback::KINDS))],
            'message' => ['required', 'string', 'min:3', 'max:5000'],
            'page' => ['nullable', 'string', 'max:2048'],
        ]);
        $page = isset($data['page']) && str_starts_with((string) $data['page'], rtrim((string) config('app.url'), '/').'/') ? (string) $data['page'] : null;
        $submit->handle($user, (string) $data['kind'], (string) $data['message'], $page);

        return response()->json(['message' => __('Thanks — your feedback has been sent.')], 201);
    }
}
