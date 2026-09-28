<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Data\Deploy\VerifiedRepositoryWebhook;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Services\Deploy\Previews;
use Illuminate\Database\UniqueConstraintViolationException;

final class HandlePullRequestWebhook
{
    /**
     * Create a new HandlePullRequestWebhook instance.
     *
     * Turns verified pull-request events into previews.
     *
     * @param  Previews  $previews  Creates, updates and closes previews.
     */
    public function __construct(private readonly Previews $previews) {}

    /**
     * Record a verified pull-request event once per delivery ID and pass it to the preview lifecycle, noting the outcome
     * on the delivery.
     *
     * @param  Repository  $repository
     * @param  VerifiedRepositoryWebhook  $webhook
     * @return string duplicate, or the preview's status or why the event was ignored or refused
     */
    public function handle(Repository $repository, VerifiedRepositoryWebhook $webhook): string
    {
        $delivery = new RepositoryWebhookDelivery;
        try {
            $delivery->forceFill([
                'repository_id' => $repository->id, 'delivery_id' => $webhook->deliveryId, 'status' => 'received',
                'revision' => $webhook->revision, 'commit_message' => $webhook->pullRequestTitle,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }
        $repository->forceFill(['webhook_last_received_at' => now()])->save();
        $status = $this->previews->receive($repository, $webhook);
        $delivery->forceFill(['status' => $status])->save();

        return $status;
    }
}
