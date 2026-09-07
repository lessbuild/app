<?php

namespace App\Http\Controllers;

use App\Jobs\Web\CreateWebsiteBackupJob;
use App\Jobs\Web\RestoreWebsiteBackupJob;
use App\Models\BackupDestination;
use App\Models\BackupRestore;
use App\Models\Website;
use App\Models\WebsiteBackup;
use App\Models\WebsiteBackupSchedule;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
    public function storeDestination(Request $request): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization->permits($request->user(), 'manage'), 403);
        $this->entitlements->enforce($organization, 'backups');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'endpoint' => ['required', 'url:https', 'max:255'],
            'bucket' => ['required', 'string', 'max:63', 'regex:/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/i'],
            'region' => ['required', 'string', 'max:100'],
            'access_key' => ['required', 'string', 'max:1000'],
            'secret_key' => ['required', 'string', 'max:1000'],
            'path_prefix' => ['required', 'string', 'max:200', 'regex:/\A[a-zA-Z0-9._\/-]+\z/'],
        ]);
        $organization->backupDestinations()->create([
            ...$data,
            'created_by' => $request->user()->id,
            'repository_password' => Str::password(40),
            'is_active' => true,
        ]);

        return back()->with('success', __('Encrypted backup destination created.'));
    }

    /**
     * Delete an authorized, entitled backup destination only when no schedules or retained backups reference it.
     *
     * @return RedirectResponse A deletion acknowledgement or the remaining-reference error.
     */
    public function destroyDestination(Request $request, BackupDestination $destination): RedirectResponse
    {
        $this->authorizeDestination($request, $destination);
        $this->entitlements->enforce($destination->organization, 'backups');
        if ($destination->schedules()->exists() || WebsiteBackup::query()->where('backup_destination_id', $destination->id)->exists()) {
            return back()->with('error', __('Remove its schedules and retained backup records before deleting this destination.'));
        }
        $destination->delete();

        return back()->with('success', __('Backup destination deleted.'));
    }

    /**
     * Validate workspace-owned website and destination IDs with UTC timing and retention, then save their schedule.
     */
    public function storeSchedule(Request $request): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization->permits($request->user(), 'manage'), 403);
        $this->entitlements->enforce($organization, 'backups');
        $data = $request->validate([
            'website_id' => ['required', Rule::exists('websites', 'id')->where('organization_id', $organization->id)],
            'backup_destination_id' => ['required', Rule::exists('backup_destinations', 'id')->where('organization_id', $organization->id)],
            'frequency' => ['required', Rule::in(['daily', 'weekly'])],
            'weekday' => ['nullable', 'integer', 'between:0,6', 'required_if:frequency,weekly'],
            'run_at' => ['required', 'date_format:H:i'],
            'retention_count' => ['required', 'integer', 'between:1,365'],
        ]);
        WebsiteBackupSchedule::query()->updateOrCreate([
            'website_id' => $data['website_id'],
            'backup_destination_id' => $data['backup_destination_id'],
        ], [...$data, 'is_active' => true]);

        return back()->with('success', __('Backup schedule saved in UTC.'));
    }

    /**
     * Require workspace management and backup access, then delete the bound schedule and redirect back.
     */
    public function destroySchedule(Request $request, WebsiteBackupSchedule $schedule): RedirectResponse
    {
        abort_unless($schedule->website->organization_id === $request->user()->current_organization_id
            && $schedule->website->organization->permits($request->user(), 'manage'), 403);
        $this->entitlements->enforce($schedule->website->organization, 'backups');
        $schedule->delete();

        return back()->with('success', __('Backup schedule deleted.'));
    }

    /**
     * Validate a workspace backup destination and queue a backup for an authorized website.
     *
     * @return RedirectResponse A queued acknowledgement, or notice that a backup is already active.
     */
    public function run(Request $request, Website $website): RedirectResponse
    {
        abort_unless($website->organization_id === $request->user()->current_organization_id
            && $website->organization->permits($request->user(), 'manage'), 403);
        $this->entitlements->enforce($website->organization, 'backups');
        $data = $request->validate([
            'backup_destination_id' => ['required', Rule::exists('backup_destinations', 'id')->where('organization_id', $website->organization_id)],
        ]);
        if ($website->backups()->whereIn('status', [WebsiteBackup::STATUS_QUEUED, WebsiteBackup::STATUS_RUNNING])->exists()) {
            return back()->with('info', __('A backup is already in progress for this website.'));
        }
        $backup = $website->backups()->create([
            'backup_destination_id' => $data['backup_destination_id'],
            'triggered_by' => $request->user()->id,
            'status' => WebsiteBackup::STATUS_QUEUED,
        ]);
        CreateWebsiteBackupJob::dispatch($backup->id);

        return back()->with('success', __('Offsite backup queued.'));
    }

    /**
     * Require the website-name confirmation and queue recovery from a completed, authorized backup.
     *
     * @return RedirectResponse The queued result, or an incomplete-backup or active-deployment error.
     */
    public function restore(Request $request, WebsiteBackup $backup): RedirectResponse
    {
        $backup->loadMissing('website.organization');
        abort_unless($backup->website->organization_id === $request->user()->current_organization_id
            && $backup->website->organization->permits($request->user(), 'manage'), 403);
        $this->entitlements->enforce($backup->website->organization, 'backups');
        $request->validate(['confirmation' => ['required', Rule::in([$backup->website->name])]]);
        if ($backup->status !== WebsiteBackup::STATUS_SUCCEEDED || ! $backup->snapshot_id) {
            return back()->with('error', __('Only completed backups can be restored.'));
        }
        if ($backup->website->hasActiveDeployment()) {
            return back()->with('error', __('Wait for the active deployment to finish before restoring.'));
        }
        $restore = DB::transaction(fn () => $backup->restores()->create([
            'requested_by' => $request->user()->id,
            'status' => BackupRestore::STATUS_QUEUED,
        ]));
        RestoreWebsiteBackupJob::dispatch($restore->id);

        return back()->with('success', __('Restore queued with automatic safety rollback.'));
    }

    /**
     * Abort with 403 unless the destination belongs to the current workspace and the user can manage it.
     */
    private function authorizeDestination(Request $request, BackupDestination $destination): void
    {
        abort_unless($destination->organization_id === $request->user()->current_organization_id
            && $destination->organization->permits($request->user(), 'manage'), 403);
    }
}
