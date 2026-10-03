<?php

declare(strict_types=1);

namespace App\Actions\Roadmap;

use App\Models\FeatureRequest;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class LinkFeedbackToFeatureRequest
{
    /**
     * Link feedback to a roadmap request: its sender votes for the request (once), and the feedback is resolved.
     *
     * @param  User  $admin  the platform admin linking it
     * @param  FeatureRequest  $request
     * @param  Feedback  $feedback
     * @return void
     */
    public function handle(User $admin, FeatureRequest $request, Feedback $feedback): void
    {
        DB::transaction(function () use ($admin, $request, $feedback): void {
            $feedback->forceFill(['feature_request_id' => $request->id, 'resolved_by' => $feedback->resolved_by ?? $admin->id, 'resolved_at' => $feedback->resolved_at ?? now()])->save();
            if ($feedback->user_id !== null) {
                DB::table('feature_request_votes')->insertOrIgnore(['feature_request_id' => $request->id, 'user_id' => $feedback->user_id, 'created_at' => now()]);
                $request->forceFill(['votes_count' => DB::table('feature_request_votes')->where('feature_request_id', $request->id)->count()])->save();
            }
        });
    }
}
