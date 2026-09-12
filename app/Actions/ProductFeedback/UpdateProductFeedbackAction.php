<?php

namespace App\Actions\ProductFeedback;

use App\Models\ProductFeedback;
use App\Models\User;

class UpdateProductFeedbackAction
{
    /**
     * Save a workspace review and preserve the existing resolved-at transition semantics.
     *
     * @param  ProductFeedback  $feedback  Feedback already authorized for review.
     * @param  User  $reviewer  Account recorded as the reviewer.
     * @param  array{status: string, review_response: ?string}  $attributes  Validated review fields.
     */
    public function handle(ProductFeedback $feedback, User $reviewer, array $attributes): ProductFeedback
    {
        $feedback->update([
            ...$attributes,
            'reviewed_by' => $reviewer->id,
            'resolved_at' => in_array($attributes['status'], ['resolved', 'closed'], true)
                ? ($feedback->resolved_at ?? now())
                : null,
        ]);

        return $feedback;
    }
}
