<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\BackupDestination;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteBackupSchedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveBackupSchedule
{
    /**
     * Schedule backups of a website to a destination (one schedule per destination; saving again changes it).
     *
     * @param  User  $actor
     * @param  Website  $website
     * @param  array{backup_destination_id: int|string, frequency: string, weekday?: int|string|null, run_at: string, retention_count: int|string, secondary_destination_id?: int|string|null, monthly_drill?: bool}  $data
     * @return WebsiteBackupSchedule
     */
    public function handle(User $actor, Website $website, array $data): WebsiteBackupSchedule
    {
        Gate::forUser($actor)->authorize('backUp', $website);
        $destination = BackupDestination::query()->where('account_id', $website->account_id)->findOrFail((int) $data['backup_destination_id']);
        $secondary = filled($data['secondary_destination_id'] ?? null) ? BackupDestination::query()->where('account_id', $website->account_id)->findOrFail((int) $data['secondary_destination_id']) : null;
        if ($secondary !== null && $secondary->is($destination)) {
            throw ValidationException::withMessages(['secondary_destination_id' => __('Choose a different destination for the second copy.')]);
        }
        $schedule = WebsiteBackupSchedule::query()->where('website_id', $website->id)->where('backup_destination_id', $destination->id)->first() ?? new WebsiteBackupSchedule;
        $schedule->forceFill([
            'website_id' => $website->id, 'backup_destination_id' => $destination->id,
            'frequency' => $data['frequency'], 'weekday' => $data['frequency'] === 'weekly' ? (int) ($data['weekday'] ?? 0) : null,
            'run_at' => $data['run_at'], 'retention_count' => (int) $data['retention_count'],
            'secondary_destination_id' => $secondary?->id, 'monthly_drill' => (bool) ($data['monthly_drill'] ?? true),
        ])->save();

        return $schedule;
    }
}
