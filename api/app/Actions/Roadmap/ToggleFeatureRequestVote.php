<?php

declare(strict_types=1);

namespace App\Actions\Roadmap;

use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ToggleFeatureRequestVote
{
    /**
     * Add the person's vote to a request, or take it back if they'd voted. Declined and shipped requests can't be
     * voted on. Returns whether they now vote for it.
     *
     * @param  User  $user
     * @param  FeatureRequest  $request
     * @return bool
     */
    public function handle(User $user, FeatureRequest $request): bool
    {
        abort_if(in_array($request->status, ['shipped', 'declined'], true), 422, __('Voting has closed on this request.'));

        return DB::transaction(function () use ($user, $request): bool {
            $removed = DB::table('feature_request_votes')->where('feature_request_id', $request->id)->where('user_id', $user->id)->delete();
            if ($removed === 0) {
                DB::table('feature_request_votes')->insertOrIgnore(['feature_request_id' => $request->id, 'user_id' => $user->id, 'created_at' => now()]);
            }
            $request->forceFill(['votes_count' => DB::table('feature_request_votes')->where('feature_request_id', $request->id)->count()])->save();

            return $removed === 0;
        });
    }
}
