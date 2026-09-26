<?php

namespace App\Modules\Deployer\Actions\ProductFeedback;

use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\ProductFeedback;
use App\Modules\Deployer\Models\User;

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
