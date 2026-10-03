<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\FeatureRequest;

/** Something people voted for on the roadmap has shipped. Sent to each voter. */
final class FeatureRequestShipped extends InboxNotification
{
    /**
     * Create a new FeatureRequestShipped instance.
     *
     * @param  FeatureRequest  $request  The request that shipped.
     */
    public function __construct(private readonly FeatureRequest $request) {}

    /**
     * Get the headline, naming what shipped.
     *
     * @return string
     */
    protected function title(): string
    {
        return __('Shipped: :title', ['title' => $this->request->title]);
    }

    /**
     * Get the line of detail: thanks for the vote.
     *
     * @return string
     */
    protected function body(): string
    {
        return __('You voted for this on the roadmap. It’s live now.');
    }

    /**
     * Get the roadmap's shipped section.
     *
     * @return string
     */
    protected function url(): string
    {
        return route('roadmap').'#shipped';
    }
}
