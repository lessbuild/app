<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\CompleteAccessReview;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreAccessReviewController
{
    /**
     * Complete an access review, removing what was marked, and return to the access page.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  CompleteAccessReview  $complete
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, CompleteAccessReview $complete): RedirectResponse
    {
        $data = $request->validate([
            'members' => ['array'], 'members.*' => ['string', 'size:26'],
            'tokens' => ['array'], 'tokens.*' => ['integer'],
            'grants' => ['array'], 'grants.*' => ['integer'],
        ]);
        $review = $complete->handle($user, $project, array_values($data['members'] ?? []), array_values(array_map('intval', $data['tokens'] ?? [])), array_values(array_map('intval', $data['grants'] ?? [])));

        return to_route('security.access', $project)->with('status', trans_choice('Access review saved. :count removed.|Access review saved. :count removed.', count($review->summary['removed']), ['count' => count($review->summary['removed'])]));
    }
}
