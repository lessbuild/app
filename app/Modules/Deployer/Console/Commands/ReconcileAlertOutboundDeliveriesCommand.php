<?php

namespace App\Modules\Deployer\Console\Commands;

use App\Modules\Deployer\Models\AlertOutboundDelivery;
use App\Modules\Deployer\Models\AlertOutboundDeliveryPayload;
use App\Modules\Deployer\Services\QueueAlertWebhookDelivery;
use Illuminate\Console\Command;

class ReconcileAlertOutboundDeliveriesCommand extends Command
{
    protected $signature = 'buildpusher:alert-deliveries:reconcile {--limit=100 : Maximum due deliveries to inspect}';

    protected $description = 'Recover Deployer alert outbox dispatches, expired worker leases, and expired encrypted payloads';

    public function handle(QueueAlertWebhookDelivery $deliveries): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $expired = $deliveries->expireQueued($limit);
        $purged = AlertOutboundDeliveryPayload::query()
            ->where('expires_at', '<=', now('UTC'))
            ->whereHas('delivery', fn ($query) => $query->whereIn('status', [
                AlertOutboundDelivery::STATUS_DELIVERED,
                AlertOutboundDelivery::STATUS_FAILED,
                AlertOutboundDelivery::STATUS_CANCELLED,
                AlertOutboundDelivery::STATUS_UNCERTAIN,
            ]))
            ->orderBy('expires_at')
            ->limit($limit)
            ->get()
            ->each(fn (AlertOutboundDeliveryPayload $payload) => $payload->delete())
            ->count();
        $recovered = $deliveries->recover($limit);

        $this->info("Expired {$expired} alert delivery window(s), pruned {$purged} expired encrypted payload(s), and recovered {$recovered} alert delivery dispatch(es).");

        return self::SUCCESS;
    }
}
