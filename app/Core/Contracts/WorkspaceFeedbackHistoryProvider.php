<?php

namespace App\Core\Contracts;

use App\Core\Data\Feedback\WorkspaceFeedbackHistory;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;

/** Reads product-owned feedback history without moving its source records. */
interface WorkspaceFeedbackHistoryProvider
{
    /**
     * @param  array{status: string|null, category: string|null, limit: int}  $filters
     */
    public function forWorkspace(
        PlatformUser $user,
        Workspace $workspace,
        array $filters,
        bool $canReview,
    ): WorkspaceFeedbackHistory;
}
