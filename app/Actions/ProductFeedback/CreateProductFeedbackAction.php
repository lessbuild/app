<?php

namespace App\Actions\ProductFeedback;

use App\Models\Organization;
use App\Models\ProductFeedback;
use App\Models\User;

class CreateProductFeedbackAction
{
    /**
     * Persist private feedback for a selected workspace and record its submitter.
     *
     * @param  Organization  $organization  Workspace that owns the feedback.
     * @param  User  $submitter  Account submitting the feedback.
     * @param  array<string, mixed>  $attributes  Validated feedback fields.
     */
    public function handle(Organization $organization, User $submitter, array $attributes): ProductFeedback
    {
        return $organization->productFeedback()->create([
            ...$attributes,
            'user_id' => $submitter->id,
            'status' => 'open',
        ]);
    }
}
