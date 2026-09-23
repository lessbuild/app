<?php

namespace App\Modules\Deployer\Actions\ProductFeedback;

use App\Modules\Deployer\Models\ProductFeedback;

class DeleteProductFeedbackAction
{
    /**
     * Remove feedback after the resource policy has verified submitter or manager access.
     */
    public function handle(ProductFeedback $feedback): void
    {
        $feedback->delete();
    }
}
