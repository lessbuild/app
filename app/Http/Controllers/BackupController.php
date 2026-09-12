<?php

namespace App\Http\Controllers;

use App\Actions\Backup\CreateBackupDestinationAction;
use App\Actions\Backup\DeleteBackupDestinationAction;
use App\Actions\Backup\DeleteBackupScheduleAction;
use App\Actions\Backup\QueueWebsiteBackupAction;
use App\Actions\Backup\RequestWebsiteBackupRestoreAction;
use App\Actions\Backup\SaveBackupScheduleAction;
use App\Exceptions\BackupDestinationInUseException;
use App\Exceptions\BackupRestoreException;
use App\Http\Requests\RestoreWebsiteBackupRequest;
use App\Http\Requests\RunWebsiteBackupRequest;
use App\Http\Requests\StoreBackupDestinationRequest;
use App\Http\Requests\StoreBackupScheduleRequest;
use App\Models\BackupDestination;
use App\Models\BackupRestore;
use App\Models\Organization;
use App\Models\Website;
use App\Models\WebsiteBackup;
use App\Models\WebsiteBackupSchedule;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BackupController extends Controller
{
    /**
     * Use workspace entitlements to gate backup configuration and recovery operations.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Render the current workspace's recent backups, destinations, schedules, and recovery metrics.
     */
    public function index(Request $request): View
    {
        $organization = $request->user()->currentOrganization;
        $backups = WebsiteBackup::query()
            ->whereHas('website', fn ($query) => $query->where('organization_id', $organization->id))
            ->with(['website', 'destination', 'restores'])->latest()->limit(50)->get();
        $latestBackup = $backups->where('status', WebsiteBackup::STATUS_SUCCEEDED)->sortByDesc('completed_at')->first();
        $latestRestore = $backups->flatMap->restores->where('status', BackupRestore::STATUS_SUCCEEDED)->sortByDesc('completed_at')->first();

        return view('backups.index', [
            'destinations' => $organization->backupDestinations()->latest()->get(),
            'websites' => $organization->websites()->with(['backupSchedules.destination'])->orderBy('name')->get(),
            'backups' => $backups,
            'recoveryMetrics' => [
                'last_recovery_point' => $latestBackup?->completed_at,
                'last_restore_drill' => $latestRestore?->completed_at,
                'last_restore_seconds' => $latestRestore?->started_at && $latestRestore?->completed_at ? $latestRestore->started_at->diffInSeconds($latestRestore->completed_at) : null,
            ],
            'canManage' => $organization->permits($request->user(), 'manage'),
        ]);
    }

    /**
     * Validate an S3-compatible destination and credentials, then redirect after creating its encryption password.
     *
     * Requires workspace management access and the backups entitlement.
     */
    public function storeDestination(StoreBackupDestinationRequest $request, CreateBackupDestinationAction $createDestination): RedirectResponse
    {
        $this->authorize('create', BackupDestination::class);
        /** @var Organization $organization */
        $organization = $request->user()->currentOrganization;
        $createDestination->handle($organization, $request->user(), $request->validated());

        return back()->with('success', __('Encrypted backup destination created.'));
    }

    /**
     * Delete an authorized, entitled backup destination only when no schedules or retained backups reference it.
     *
     * @return RedirectResponse A deletion acknowledgement or the remaining-reference error.
     */
    public function destroyDestination(BackupDestination $destination, DeleteBackupDestinationAction $deleteDestination): RedirectResponse
    {
        $this->authorize('delete', $destination);
        $this->entitlements->enforce($destination->organization, 'backups');
        try {
            $deleteDestination->handle($destination);
        } catch (BackupDestinationInUseException) {
            return back()->with('error', __('Remove its schedules and retained backup records before deleting this destination.'));
        }

        return back()->with('success', __('Backup destination deleted.'));
    }

    /**
     * Validate workspace-owned website and destination IDs with UTC timing and retention, then save their schedule.
     */
    public function storeSchedule(StoreBackupScheduleRequest $request, SaveBackupScheduleAction $saveSchedule): RedirectResponse
    {
        $this->authorize('create', WebsiteBackupSchedule::class);
        /** @var Organization $organization */
        $organization = $request->user()->currentOrganization;
        $data = $request->validated();
        $website = $organization->websites()->findOrFail($data['website_id']);
        $destination = $organization->backupDestinations()->findOrFail($data['backup_destination_id']);
        $saveSchedule->handle($website, $destination, $data);

        return back()->with('success', __('Backup schedule saved in UTC.'));
    }

    /**
     * Require workspace management and backup access, then delete the bound schedule and redirect back.
     */
    public function destroySchedule(WebsiteBackupSchedule $schedule, DeleteBackupScheduleAction $deleteSchedule): RedirectResponse
    {
        $this->authorize('delete', $schedule);
        $this->entitlements->enforce($schedule->website->organization, 'backups');
        $deleteSchedule->handle($schedule);

        return back()->with('success', __('Backup schedule deleted.'));
    }

    /**
     * Validate a workspace backup destination and queue a backup for an authorized website.
     *
     * @return RedirectResponse A queued acknowledgement, or notice that a backup is already active.
     */
    public function run(RunWebsiteBackupRequest $request, Website $website, QueueWebsiteBackupAction $queueBackup): RedirectResponse
    {
        $this->authorize('backup', $website);
        $data = $request->validated();
        $destination = $website->organization->backupDestinations()->findOrFail($data['backup_destination_id']);
        if ($queueBackup->handle($website, $destination, $request->user()) === null) {
            return back()->with('info', __('A backup is already in progress for this website.'));
        }

        return back()->with('success', __('Offsite backup queued.'));
    }

    /**
     * Require the website-name confirmation and queue recovery from a completed, authorized backup.
     *
     * @return RedirectResponse The queued result, or an incomplete-backup or active-deployment error.
     */
    public function restore(RestoreWebsiteBackupRequest $request, WebsiteBackup $backup, RequestWebsiteBackupRestoreAction $requestRestore): RedirectResponse
    {
        $this->authorize('restore', $backup);
        try {
            $requestRestore->handle($backup, $request->user());
        } catch (BackupRestoreException $exception) {
            return back()->with('error', __($exception->getMessage()));
        }

        return back()->with('success', __('Restore queued with automatic safety rollback.'));
    }
}
