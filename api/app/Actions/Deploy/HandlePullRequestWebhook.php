<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Data\Deploy\VerifiedRepositoryWebhook;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Services\Deploy\Previews;
use Illuminate\Support\Facades\DB;

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
        // Checked under the repository's lock rather than by catching the unique index, which would abort an enclosing
        // PostgreSQL transaction.
        $delivery = DB::transaction(function () use ($repository, $webhook): ?RepositoryWebhookDelivery {
            $locked = Repository::query()->lockForUpdate()->findOrFail($repository->id);
            if ($locked->webhookDeliveries()->where('delivery_id', $webhook->deliveryId)->exists()) {
                return null;
            }
            $delivery = new RepositoryWebhookDelivery;
            $delivery->forceFill([
                'repository_id' => $locked->id, 'delivery_id' => $webhook->deliveryId, 'status' => 'received',
                'revision' => $webhook->revision, 'commit_message' => $webhook->pullRequestTitle,
            ])->save();
            $locked->forceFill(['webhook_last_received_at' => now()])->save();

            return $delivery;
        });
        if ($delivery === null) {
            return 'duplicate';
        }
        $status = $this->previews->receive($repository, $webhook);
        $delivery->forceFill(['status' => $status])->save();

        return $status;
    }
}
