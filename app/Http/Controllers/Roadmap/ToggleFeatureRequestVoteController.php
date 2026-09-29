<?php

declare(strict_types=1);

namespace App\Http\Controllers\Roadmap;

use App\Actions\Roadmap\ToggleFeatureRequestVote;
use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class ToggleFeatureRequestVoteController
{
    /**
     * Vote for a roadmap request, or take the vote back, and return to the roadmap.
     *
     * @param  User  $user
     * @param  FeatureRequest  $featureRequest
     * @param  ToggleFeatureRequestVote  $toggle
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, FeatureRequest $featureRequest, ToggleFeatureRequestVote $toggle): RedirectResponse
    {
        $voted = $toggle->handle($user, $featureRequest);

        return redirect()->to(route('roadmap').'#request-'.$featureRequest->id)
            ->with('status', $voted ? __('Thanks — your vote is in.') : __('Vote taken back.'));
    }
}
