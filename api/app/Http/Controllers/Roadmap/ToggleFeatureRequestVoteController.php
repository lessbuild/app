<?php

declare(strict_types=1);

namespace App\Http\Controllers\Roadmap;

use App\Actions\Roadmap\ToggleFeatureRequestVote;
use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ToggleFeatureRequestVoteController
{
    /**
     * Vote for a roadmap request, or take the vote back, and say which it was.
     *
     * @param  User  $user
     * @param  FeatureRequest  $featureRequest
     * @param  ToggleFeatureRequestVote  $toggle
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, FeatureRequest $featureRequest, ToggleFeatureRequestVote $toggle): JsonResponse
    {
        $voted = $toggle->handle($user, $featureRequest);

        return response()->json(['voted' => $voted, 'message' => $voted ? __('Thanks — your vote is in.') : __('Vote taken back.')]);
    }
}
