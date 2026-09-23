<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\StatusPage;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Support\Collection;

final class WorkspaceOnboardingProgress
{
    /**
     * @return array{completed: int, total: int, percentage: int, items: Collection<int, array{key: string, label: string, description: string, route: string, complete: bool}>}
     */
    public function forWorkspace(Workspace $workspace): array
    {
        $items = collect([
            [
                'key' => 'application', 'label' => 'Connect an application',
                'description' => 'Create a service and get a private collector token.',
                'route' => 'applications.create', 'complete' => $workspace->applications()->exists(),
            ],
            [
                'key' => 'event', 'label' => 'Send your first event',
                'description' => 'Verify telemetry collection from any stack.',
                'route' => 'settings.integrations', 'complete' => Environment::forWorkspace($workspace)->where('event_count', '>', 0)->exists(),
            ],
            [
                'key' => 'monitor', 'label' => 'Add a health monitor',
                'description' => 'Watch HTTP, DNS, TLS, heartbeat, or queue health.',
                'route' => 'monitors.create', 'complete' => Monitor::forWorkspace($workspace)->exists(),
            ],
            [
                'key' => 'alerts', 'label' => 'Configure alert delivery',
                'description' => 'Get notified when a signal needs attention.',
                'route' => 'alert-destinations.create', 'complete' => AlertDestination::forWorkspace($workspace)->where('enabled', true)->exists(),
            ],
            [
                'key' => 'status-page', 'label' => 'Publish a status page',
                'description' => 'Give customers a stable view of service health.',
                'route' => 'status-pages.create', 'complete' => StatusPage::query()->whereBelongsTo($workspace)->where('published', true)->exists(),
            ],
        ]);
        $completed = $items->where('complete', true)->count();
        $total = $items->count();

        return [
            'completed' => $completed,
            'total' => $total,
            'percentage' => $total > 0 ? (int) round($completed / $total * 100) : 100,
            'items' => $items,
        ];
    }
}
