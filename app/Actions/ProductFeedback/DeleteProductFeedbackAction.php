<?php

namespace App\Actions\ProductFeedback;

use App\Models\ProductFeedback;

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
