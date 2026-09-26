<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Data\Projects\WorkspaceWebhookDelivery;
use App\Core\Models\Project as CoreProject;
use App\Modules\Deployer\Models\AlertOutboundDelivery;
use App\Modules\Deployer\Models\Environment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class DeployerAlertDeliveryActivity
{
    public function __construct(private readonly DeployerOperationalResourceMap $resourceMap) {}

    /**
     * Project outbound alert deliveries attributed to mapped environments or unambiguously mapped websites.
     * Workspace-scoped server, provider, and metric alerts stay in Deployer's native history.
     *
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     * @return Collection<int, WorkspaceWebhookDelivery>
     */
    public function deliveryHistoryForMappedEnvironments(array $mappedEnvironments, int $limit): Collection
    {
        if ($mappedEnvironments === []
            || ! Schema::connection('deployer')->hasTable('alert_outbound_deliveries')
            || ! Schema::connection('deployer')->hasColumn('alert_outbound_deliveries', 'environment_id')) {
            return collect();
        }

        $websiteMappings = Schema::connection('deployer')->hasTable('websites')
            ? $this->resourceMap->websites($mappedEnvironments)
            : [];
        $environmentIds = array_map('intval', array_keys($mappedEnvironments));
        $websiteIds = array_map('intval', array_keys($websiteMappings));
        $cutoff = CarbonImmutable::now('UTC')->subDays(30);

        return AlertOutboundDelivery::query()
            ->where(function ($query) use ($environmentIds, $websiteIds): void {
                $query->whereIn('environment_id', $environmentIds);
                if ($websiteIds !== []) {
                    $query->orWhere(fn ($website) => $website->whereNull('environment_id')->whereIn('website_id', $websiteIds));
                }
            })
            ->where(function ($query) use ($cutoff): void {
                $query->whereIn('status', [
                    AlertOutboundDelivery::STATUS_QUEUED,
                    AlertOutboundDelivery::STATUS_SENDING,
                    AlertOutboundDelivery::STATUS_RETRYING,
                    AlertOutboundDelivery::STATUS_FAILED,
                    AlertOutboundDelivery::STATUS_UNCERTAIN,
                ])->orWhere(fn ($terminal) => $terminal
                    ->whereIn('status', [AlertOutboundDelivery::STATUS_DELIVERED, AlertOutboundDelivery::STATUS_CANCELLED])
                    ->where('updated_at', '>=', $cutoff));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get(['id', 'organization_id', 'environment_id', 'website_id', 'destination_type', 'event', 'status', 'attempt_count', 'created_at'])
            ->map(function (AlertOutboundDelivery $delivery) use ($mappedEnvironments, $websiteMappings): ?WorkspaceWebhookDelivery {
                $mapping = $delivery->environment_id !== null
                    ? ($mappedEnvironments[(string) $delivery->environment_id] ?? null)
                    : ($websiteMappings[(string) $delivery->website_id] ?? null);

                // The attributed resource must still belong to the delivery's own workspace.
                if ($mapping === null || (int) $delivery->organization_id !== $mapping['organization_id']) {
                    return null;
                }

                $status = (string) $delivery->status;

                return new WorkspaceWebhookDelivery(
                    key: 'deployer:alert-delivery:'.$delivery->getKey(),
                    product: 'deployer',
                    productLabel: (string) config('platform.products.deployer.label', __('Deployer')),
                    projectName: $mapping['project']->name,
                    title: __(':event alert to :destination', [
                        'event' => str((string) $delivery->event)->headline(),
                        'destination' => str((string) $delivery->destination_type)->headline(),
                    ]),
                    status: $status,
                    statusLabel: match ($status) {
                        AlertOutboundDelivery::STATUS_QUEUED => __('Queued'),
                        AlertOutboundDelivery::STATUS_SENDING => __('Sending'),
                        AlertOutboundDelivery::STATUS_RETRYING => __('Retrying'),
                        AlertOutboundDelivery::STATUS_DELIVERED => __('Delivered'),
                        AlertOutboundDelivery::STATUS_FAILED => __('Failed'),
                        AlertOutboundDelivery::STATUS_CANCELLED => __('Cancelled'),
                        AlertOutboundDelivery::STATUS_UNCERTAIN => __('Uncertain'),
                        default => __('Unknown'),
                    },
                    attemptCount: (int) $delivery->attempt_count,
                    recordedAt: $delivery->created_at?->toImmutable()->utc() ?? CarbonImmutable::now('UTC'),
                    resultUrl: Route::has('observability.index')
                        ? route('observability.index', [
                            'section' => 'alert-delivery-history',
                            'organization_id' => $mapping['organization_id'],
                        ])
                        : null,
                );
            })
            ->filter()
            ->values();
    }
}
