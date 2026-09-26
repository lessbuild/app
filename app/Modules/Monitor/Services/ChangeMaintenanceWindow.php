<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\MaintenanceWindow;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ChangeMaintenanceWindow
{
    /** @param array<string, mixed> $data */
    public function save(Workspace $workspace, User $actor, array $data, ?MaintenanceWindow $window = null): MaintenanceWindow
    {
        return DB::connection('monitor')->transaction(function () use ($workspace, $actor, $data, $window): MaintenanceWindow {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::forUser($actor)->authorize('update', $workspace);
            $maintenanceWindow = $window === null
                ? new MaintenanceWindow(['workspace_id' => $workspace->id, 'created_by' => $actor->id])
                : $workspace->maintenanceWindows()->lockForUpdate()->findOrFail($window->id);
            $startsAt = $this->parse($data['starts_at'] ?? null);
            $endsAt = $this->parse($data['ends_at'] ?? null);

            if ($startsAt === null || $endsAt === null || ! $endsAt->greaterThan($startsAt)) {
                throw ValidationException::withMessages(['ends_at' => 'The maintenance window must end after it starts.']);
            }

            $maintenanceWindow->forceFill([
                'workspace_id' => $workspace->id,
                'created_by' => $maintenanceWindow->created_by ?? $actor->id,
                'name' => trim((string) $data['name']),
                'reason' => isset($data['reason']) && trim((string) $data['reason']) !== '' ? trim((string) $data['reason']) : null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ])->save();

            return $maintenanceWindow;
        }, attempts: 3);
    }

    public function delete(Workspace $workspace, User $actor, MaintenanceWindow $window): void
    {
        DB::connection('monitor')->transaction(function () use ($workspace, $actor, $window): void {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::forUser($actor)->authorize('update', $workspace);
            $workspace->maintenanceWindows()->lockForUpdate()->findOrFail($window->id)->delete();
        }, attempts: 3);
    }

    private function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', trim($value), 'UTC');

        return $parsed instanceof CarbonImmutable ? $parsed : null;
    }
}
