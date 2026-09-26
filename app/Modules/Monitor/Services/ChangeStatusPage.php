<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\StatusPage;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ChangeStatusPage
{
    /** @param array<string, mixed> $data */
    public function save(Workspace $workspace, User $actor, array $data, ?StatusPage $statusPage = null): StatusPage
    {
        return DB::connection('monitor')->transaction(function () use ($workspace, $actor, $data, $statusPage): StatusPage {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::forUser($actor)->authorize('update', $workspace);

            $page = $statusPage === null
                ? new StatusPage(['workspace_id' => $workspace->id])
                : $workspace->statusPages()->visibleTo($actor, $workspace)->lockForUpdate()->findOrFail($statusPage->id);
            $slug = $this->slug($page, $data);

            if (StatusPage::query()->where('slug', $slug)->when($page->exists, fn ($query) => $query->whereKeyNot($page->id))->exists()) {
                throw ValidationException::withMessages(['slug' => 'That public status URL is already in use.']);
            }

            $monitorIds = array_values(array_unique(array_map('intval', $data['monitor_ids'] ?? [])));
            $monitors = Monitor::query()->forWorkspace($workspace)->visibleTo($actor, $workspace)->whereKey($monitorIds)->orderBy('id')->get();
            if ($monitors->count() !== count($monitorIds)) {
                throw ValidationException::withMessages(['monitor_ids' => 'Choose monitors from the current workspace only.']);
            }

            $page->forceFill([
                'workspace_id' => $workspace->id,
                'name' => trim((string) $data['name']),
                'slug' => $slug,
                'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
                'published' => (bool) ($data['published'] ?? false),
            ])->save();
            $page->components()->delete();

            $monitorsById = $monitors->keyBy('id');
            foreach ($monitorIds as $position => $monitorId) {
                $monitor = $monitorsById->get($monitorId);
                $page->components()->create([
                    'monitor_id' => $monitorId,
                    'label' => $monitor->name,
                    'position' => $position,
                ]);
            }

            return $page->load('components.monitor');
        }, attempts: 3);
    }

    public function delete(Workspace $workspace, User $actor, StatusPage $statusPage): void
    {
        DB::connection('monitor')->transaction(function () use ($workspace, $actor, $statusPage): void {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::forUser($actor)->authorize('update', $workspace);
            $workspace->statusPages()->visibleTo($actor, $workspace)->lockForUpdate()->findOrFail($statusPage->id)->delete();
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    private function slug(StatusPage $statusPage, array $data): string
    {
        $requested = trim((string) ($data['slug'] ?? ''));
        if ($requested !== '') {
            return Str::slug($requested);
        }
        if ($statusPage->exists && is_string($statusPage->slug) && $statusPage->slug !== '') {
            return $statusPage->slug;
        }

        return trim(Str::slug((string) $data['name']).'-'.Str::lower(Str::random(6)), '-');
    }
}
