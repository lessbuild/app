<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\AccessRequests\ReviewAccessRequest;
use App\Models\AccessRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateAccessRequestController
{
    /**
     * Record a decision on an access request and go back to the list it was in.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $accessRequest
     * @param  ReviewAccessRequest  $review
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, string $accessRequest, ReviewAccessRequest $review): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'string'], 'review_notes' => ['nullable', 'string', 'max:2000']]);
        $record = AccessRequest::query()->findOrFail((int) $accessRequest);
        $previous = $record->status;
        $review->handle($user, $record, (string) $data['status'], isset($data['review_notes']) ? (string) $data['review_notes'] : null, $request->boolean('resend'));

        return to_route('admin.access-requests', ['status' => $previous])->with('status', $data['status'] === 'invited' ? __('Invitation sent.') : __('Saved.'));
    }
}
