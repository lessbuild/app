<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Roadmap\LinkFeedbackToFeatureRequest;
use App\Actions\Roadmap\SaveFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AddFeedbackToRoadmapController
{
    /**
     * Put a piece of feedback on the roadmap: link it to an existing request, or write up a new one from it. Either
     * way its sender votes for the request and the feedback is resolved.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Feedback  $feedback
     * @param  SaveFeatureRequest  $save
     * @param  LinkFeedbackToFeatureRequest  $link
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Feedback $feedback, SaveFeatureRequest $save, LinkFeedbackToFeatureRequest $link): RedirectResponse
    {
        $validated = $request->validate([
            'feature_request_id' => ['nullable', 'integer', Rule::exists('feature_requests', 'id')],
            'title' => ['required_without:feature_request_id', 'nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(array_keys(FeatureRequest::STATUSES))],
        ]);
        if (isset($validated['feature_request_id'])) {
            $link->handle($user, FeatureRequest::query()->whereKey((int) $validated['feature_request_id'])->firstOrFail(), $feedback);
        } else {
            $save->handle($user, null, [
                'title' => trim((string) $validated['title']),
                'description' => isset($validated['description']) ? trim((string) $validated['description']) : null,
                'status' => $validated['status'] ?? 'under_review',
            ], $feedback);
        }

        return to_route('admin.feedback')->with('status', __('Added to the roadmap. The sender’s vote is counted.'));
    }
}
