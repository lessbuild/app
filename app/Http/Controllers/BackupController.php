<?php

namespace App\Http\Controllers;

use App\Actions\Backup\CreateBackupDestinationAction;
use App\Actions\Backup\DeleteBackupDestinationAction;
use App\Actions\Backup\DeleteBackupScheduleAction;
use App\Actions\Backup\QueueWebsiteBackupAction;
use App\Actions\Backup\RequestWebsiteBackupRestoreAction;
use App\Actions\Backup\RequestWebsiteBackupVerificationAction;
use App\Actions\Backup\SaveBackupScheduleAction;
use App\Actions\Backup\TestBackupDestinationAction;
use App\Actions\Backup\UpdateBackupDestinationAction;
use App\Exceptions\BackupDestinationConnectionException;
use App\Exceptions\BackupDestinationInUseException;
use App\Exceptions\BackupDestinationUpdateException;
use App\Exceptions\BackupRestoreException;
use App\Http\Requests\RestoreWebsiteBackupRequest;
use App\Http\Requests\RunWebsiteBackupRequest;
use App\Http\Requests\StoreBackupDestinationRequest;
use App\Http\Requests\StoreBackupScheduleRequest;
use App\Http\Requests\TestBackupDestinationRequest;
use App\Http\Requests\UpdateBackupDestinationRequest;
use App\Http\Requests\VerifyWebsiteBackupRequest;
use App\Models\BackupDestination;
use App\Models\Organization;
use App\Models\Website;
use App\Models\WebsiteBackup;
use App\Models\WebsiteBackupSchedule;
use App\Services\BackupDestinationCatalog;
use App\Services\BackupRecoveryEvidenceQuery;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BackupController extends Controller
{
    /**
     * Use workspace entitlements to gate backup configuration and recovery operations.
     */
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly BackupRecoveryEvidenceQuery $recoveryEvidence,
        private readonly BackupDestinationCatalog $destinationCatalog,
    ) {}

    /**
     * Render the current workspace's recent backups, destinations, schedules, and recovery metrics.
     */
    public function index(Request $request): View
    {
        $organization = $request->user()->currentOrganization;
        $backups = $this->recoveryEvidence->recentBackups($organization);
        $recoverySummary = $this->recoveryEvidence->summary($organization);

        return view('backups.index', [
            'destinations' => $organization->backupDestinations()->latest()->get(),
            'websites' => $organization->websites()->with(['backupSchedules.destination', 'server'])->orderBy('name')->get(),
            'backups' => $backups,
            'recoverySummary' => $recoverySummary,
            'destinationCatalog' => $this->destinationCatalog,
            'destinationPresets' => $this->destinationCatalog->all(),
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
     * Validate editable destination details and preserve retained snapshot locations while rotating its connection.
     *
     * @return RedirectResponse The unverified updated destination, or the existing safety rejection.
     */
    public function updateDestination(UpdateBackupDestinationRequest $request, BackupDestination $destination, UpdateBackupDestinationAction $updateDestination): RedirectResponse
    {
        $this->authorize('update', $destination);
        try {
            $updateDestination->handle($destination, $request->validated());
        } catch (BackupDestinationUpdateException $exception) {
            return back()->with('error', __($exception->getMessage()));
        }

        return back()->with('success', __('Backup destination updated. Verify it before the next backup.'));
    }

    /** Verify a destination with a temporary S3-compatible object without requiring a managed website server. */
    public function testDestination(TestBackupDestinationRequest $request, BackupDestination $destination, TestBackupDestinationAction $testDestination): RedirectResponse
    {
        $this->authorize('test', $destination);
        try {
            $testDestination->handle($destination);
        } catch (BackupDestinationConnectionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Backup destination verified and ready for backups.'));
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

    /**
     * Require the website-name confirmation and queue an isolated recovery verification without overwriting live data.
     */
    public function verify(VerifyWebsiteBackupRequest $request, WebsiteBackup $backup, RequestWebsiteBackupVerificationAction $requestVerification): RedirectResponse
    {
        $this->authorize('verify', $backup);
        try {
            $requestVerification->handle($backup, $request->user());
        } catch (BackupRestoreException $exception) {
            return back()->with('error', __($exception->getMessage()));
        }

        return back()->with('success', __('Isolated restore verification queued.'));
    }
}
