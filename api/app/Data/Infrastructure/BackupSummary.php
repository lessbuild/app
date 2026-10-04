<?php

declare(strict_types=1);

namespace App\Data\Infrastructure;

use App\Models\BackupRestore;
use App\Models\BackupVerification;
use App\Models\WebsiteBackup;

final readonly class BackupSummary
{
    /**
     * Create a new BackupSummary instance.
     *
     * A website backup as lists show it, with its latest restore and verification.
     *
     * @param  int  $id
     * @param  int  $websiteId
     * @param  string  $website  The website's name (it may have been deleted since).
     * @param  string  $status  queued, running, succeeded or failed.
     * @param  string  $destination
     * @param  int|null  $sizeBytes
     * @param  bool  $scheduled  Whether a schedule took it.
     * @param  string|null  $secondaryStatus  copied or failed, when the schedule copies backups elsewhere too.
     * @param  string|null  $error
     * @param  array{status: string, error: string|null}|null  $restore
     * @param  array{status: string, error: string|null}|null  $verification
     * @param  bool  $restorable
     * @param  string|null  $createdAt  ISO 8601.
     */
    public function __construct(
        public int $id,
        public int $websiteId,
        public string $website,
        public string $status,
        public string $destination,
        public ?int $sizeBytes,
        public bool $scheduled,
        public ?string $secondaryStatus,
        public ?string $error,
        public ?array $restore,
        public ?array $verification,
        public bool $restorable,
        public ?string $createdAt,
    ) {}

    /**
     * Describe a backup (with its website, destination, restores and verifications loaded, newest first).
     *
     * @param  WebsiteBackup  $backup
     * @return self
     */
    public static function from(WebsiteBackup $backup): self
    {
        $restore = $backup->restores->first();
        $verification = $backup->verifications->first();

        return new self(
            id: $backup->id,
            websiteId: $backup->website_id,
            website: $backup->website->name,
            status: $backup->status,
            destination: $backup->destination->name,
            sizeBytes: $backup->size_bytes,
            scheduled: $backup->website_backup_schedule_id !== null,
            secondaryStatus: $backup->secondary_status,
            error: $backup->error,
            restore: $restore instanceof BackupRestore ? ['status' => $restore->status, 'error' => $restore->error] : null,
            verification: $verification instanceof BackupVerification ? ['status' => $verification->status, 'error' => $verification->error] : null,
            restorable: $backup->isRestorable(),
            createdAt: $backup->created_at?->toIso8601String(),
        );
    }
}
