<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Dashboard;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChangeDashboard
{
    public function __construct(private readonly WorkspacePlanLimits $limits) {}

    /** @param array<string, mixed> $data */
    public function save(Workspace $workspace, User $actor, array $data, ?Dashboard $dashboard = null): Dashboard
    {
        return DB::connection('monitor')->transaction(function () use ($workspace, $actor, $data, $dashboard): Dashboard {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::forUser($actor)->authorize('update', $workspace);

            if ($dashboard === null) {
                $this->limits->assertDashboardCapacity($workspace);
                $dashboard = new Dashboard(['workspace_id' => $workspace->id, 'created_by' => $actor->id]);
            } else {
                $dashboard = $workspace->dashboards()->lockForUpdate()->findOrFail($dashboard->id);
            }

            $types = array_values(array_unique(array_map(
                static fn (mixed $type): string => (string) $type,
                is_array($data['widgets'] ?? null) ? $data['widgets'] : [],
            )));
            $invalidTypes = array_diff($types, array_keys(Dashboard::WIDGET_TYPES));
            if ($types === [] || $invalidTypes !== []) {
                throw ValidationException::withMessages(['widgets' => 'Choose at least one supported dashboard widget.']);
            }

            $dashboard->forceFill([
                'workspace_id' => $workspace->id,
                'created_by' => $dashboard->created_by ?? $actor->id,
                'name' => trim((string) $data['name']),
                'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
                'range' => (string) $data['range'],
            ])->save();
            $dashboard->widgets()->delete();

            foreach ($types as $position => $type) {
                $dashboard->widgets()->create(['type' => $type, 'position' => $position]);
            }

            return $dashboard->load('widgets');
        }, attempts: 3);
    }

    public function delete(Workspace $workspace, User $actor, Dashboard $dashboard): void
    {
        DB::connection('monitor')->transaction(function () use ($workspace, $actor, $dashboard): void {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::forUser($actor)->authorize('update', $workspace);
            $workspace->dashboards()->lockForUpdate()->findOrFail($dashboard->id)->delete();
        }, attempts: 3);
    }
}
