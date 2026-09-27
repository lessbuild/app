<?php

declare(strict_types=1);

namespace App\Queries\Infrastructure;

use App\Models\BackupRestore;
use App\Models\BackupVerification;
use App\Models\Website;
use App\Models\WebsiteBackup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** Recent backups across the account's websites, and when recovery was last shown to work. */
final class BackupsQuery
{
    /** @return Collection<int, WebsiteBackup> */
    public function recent(string $accountId, ?Website $website = null, int $limit = 50): Collection
    {
        return $this->backups($accountId)->when($website !== null, fn (Builder $query) => $query->where('website_id', $website?->id))
            ->with(['website', 'destination', 'restores' => fn ($query) => $query->latest('id'), 'verifications' => fn ($query) => $query->latest('id')])
            ->latest('id')->limit($limit)->get();
    }

    /** @return array{backup: CarbonImmutable|null, restore: CarbonImmutable|null, restore_seconds: int|null, verification: CarbonImmutable|null} */
    public function summary(string $accountId): array
    {
        $backup = $this->backups($accountId)->where('status', WebsiteBackup::STATUS_SUCCEEDED)->latest('completed_at')->first();
        $restore = BackupRestore::query()->where('status', 'succeeded')->whereIn('website_backup_id', $this->backups($accountId)->select('id'))->latest('completed_at')->first();
        $verification = BackupVerification::query()->where('status', 'succeeded')->whereIn('website_backup_id', $this->backups($accountId)->select('id'))->latest('completed_at')->first();

        return [
            'backup' => $backup?->completed_at,
            'restore' => $restore?->completed_at,
            'restore_seconds' => $restore?->started_at !== null && $restore->completed_at !== null ? (int) $restore->started_at->diffInSeconds($restore->completed_at) : null,
            'verification' => $verification?->completed_at,
        ];
    }

    /** @return Builder<WebsiteBackup> */
    private function backups(string $accountId): Builder
    {
        return WebsiteBackup::query()->whereIn('website_id', Website::withTrashed()->where('account_id', $accountId)->select('id'));
    }
}
