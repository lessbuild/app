<?php

declare(strict_types=1);

namespace App\Actions\Roadmap;

use App\Models\FeatureRequest;
use App\Models\Feedback;
use App\Models\User;
use App\Notifications\FeatureRequestShipped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class SaveFeatureRequest
{
    /**
     * Create a new SaveFeatureRequest instance.
     *
     * @param  LinkFeedbackToFeatureRequest  $link  Links the feedback a request is written up from.
     */
    public function __construct(private readonly LinkFeedbackToFeatureRequest $link) {}

    /**
     * Create or update a roadmap request. Written up from feedback, it links that feedback, counts its sender as a
     * voter and resolves it. Moving to shipped tells everyone who voted.
     *
     * @param  User  $admin  the platform admin writing it
     * @param  FeatureRequest|null  $request  null to create one
     * @param  array{title: string, description: ?string, status: string}  $data
     * @param  Feedback|null  $from  the feedback it answers
     * @return FeatureRequest
     */
    public function handle(User $admin, ?FeatureRequest $request, array $data, ?Feedback $from = null): FeatureRequest
    {
        $request ??= (new FeatureRequest)->forceFill(['created_by' => $admin->id]);
        $shipping = $data['status'] === 'shipped' && $request->status !== 'shipped';
        DB::transaction(function () use ($admin, $request, $data, $shipping, $from): void {
            $request->forceFill([
                'title' => $data['title'], 'description' => $data['description'], 'status' => $data['status'],
                'shipped_at' => $data['status'] === 'shipped' ? ($shipping ? now() : $request->shipped_at) : null,
            ])->save();
            if ($from !== null) {
                $this->link->handle($admin, $request, $from);
            }
        });
        if ($shipping) {
            Notification::send($request->voters()->get(), new FeatureRequestShipped($request));
        }

        return $request;
    }
}
