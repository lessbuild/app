<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\WorkspaceMonitorSettingsAdministrationProvider;
use App\Core\Data\Monitor\MonitorAdministrationSnapshot;
use App\Core\Data\Monitor\MonitorMutationResult;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Monitor\Models\AuditLog;
use App\Modules\Monitor\Models\IssueDigestPreference;
use App\Modules\Monitor\Services\ExportWorkspaceData;
use App\Modules\Monitor\Services\IssueDigestHistory;
use App\Modules\Monitor\Services\SaveIssueDigestPreference;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use Illuminate\Support\Facades\Gate;

final class MonitorSettingsAdministrationProvider implements WorkspaceMonitorSettingsAdministrationProvider
{
    private readonly MonitorAdministrationContext $context;

    public function __construct(
        LegacyIdentityResolver $identities,
        WorkspaceProjectAccess $workspaceAccess,
        ProductWorkspaceAccess $productAccess,
        private readonly WorkspacePlanLimits $limits,
        private readonly IssueDigestHistory $digestHistory,
        private readonly SaveIssueDigestPreference $savePreference,
        private readonly ExportWorkspaceData $export,
        private readonly TelemetryRedactor $redactor,
    ) {
        $this->context = new MonitorAdministrationContext($identities, $workspaceAccess, $productAccess);
    }

    public function snapshot(PlatformUser $user, CoreWorkspace $workspace): MonitorAdministrationSnapshot
    {
        $resolved = $this->context->resolve($user, $workspace);
        if ($resolved === null) {
            return new MonitorAdministrationSnapshot(collect(), available: false);
        }
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $resolved;
        $preference = IssueDigestPreference::query()->whereBelongsTo($sourceWorkspace)->whereBelongsTo($actor)->first();
        $history = $this->digestHistory->forRecipient($sourceWorkspace, $actor)->map(fn ($delivery): array => [
            'status' => (string) $delivery->status,
            'period_start' => $delivery->period_start,
            'period_end' => $delivery->period_end,
            'sent_at' => $delivery->sent_at,
            'attempts' => (int) $delivery->attempts,
            'last_error_code' => $delivery->last_error_code,
            'summary_available' => (bool) $delivery->summary_available,
            'new_count' => $delivery->new_count,
            'resolved_count' => $delivery->resolved_count,
            'open_count' => $delivery->open_count,
            'critical_open_count' => $delivery->critical_open_count,
        ])->values();

        return new MonitorAdministrationSnapshot($history, [
            'digest_enabled' => $preference?->enabled ?? ($actor->getKey() === $sourceWorkspace->owner_id),
            'digest_available' => $this->limits->issueDigestEnabled($sourceWorkspace),
            'is_owner' => (string) $actor->getKey() === (string) $sourceWorkspace->owner_id,
        ]);
    }

    public function integrationGuide(PlatformUser $user, CoreWorkspace $workspace): MonitorAdministrationSnapshot
    {
        $resolved = $this->context->resolve($user, $workspace);
        if ($resolved === null) {
            return new MonitorAdministrationSnapshot(collect(), available: false);
        }
        Gate::forUser($resolved['user'])->authorize('view', $resolved['workspace']);

        return new MonitorAdministrationSnapshot(collect([
            ['title' => __('Connect a service'), 'detail' => __('Create an application and environment in Monitor before sending telemetry.')],
            ['title' => __('Choose an ingestion method'), 'detail' => __('Use the environment ingestion settings for its scoped token and supported OpenTelemetry endpoint.')],
            ['title' => __('Protect ingestion credentials'), 'detail' => __('Store tokens in a server-side secret manager. Do not commit them to source control or send them in support requests.')],
            ['title' => __('Validate delivery'), 'detail' => __('Use the environment telemetry status and processing history to confirm accepted batches and resolve rejected payloads.')],
            ['title' => __('API reference'), 'detail' => route('core.help.monitor.api')],
        ]));
    }

    public function auditSnapshot(PlatformUser $user, CoreWorkspace $workspace, array $filters = []): MonitorAdministrationSnapshot
    {
        $resolved = $this->context->resolve($user, $workspace);
        if ($resolved === null) {
            return new MonitorAdministrationSnapshot(collect(), available: false);
        }
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $resolved;
        if (! $this->limits->auditLogEnabled($sourceWorkspace)) {
            return new MonitorAdministrationSnapshot(collect(), ['audit_available' => false]);
        }
        $logs = AuditLog::forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)
            ->when(filled($filters['audit_search'] ?? null), fn ($query) => $query->where(function ($query) use ($filters): void {
                $search = '%'.trim((string) $filters['audit_search']).'%';
                $query->where('action', 'like', $search)->orWhere('metadata->label', 'like', $search)
                    ->orWhereHas('actor', fn ($actor) => $actor->where('name', 'like', $search));
            }))->with('actor:id,name')->latest('created_at')->latest('id')
            ->paginate(30, ['*'], 'audit_page')->withQueryString();

        return new MonitorAdministrationSnapshot($logs->through(function (AuditLog $log): array {
            $metadata = $this->redactor->redact(is_array($log->metadata) ? $log->metadata : []);

            return [
                'label' => $log->label(),
                'actor' => $log->actor?->name ?? __('System'),
                'subject' => $metadata['label'] ?? __('Workspace configuration'),
                'metadata' => $metadata,
                'occurred_at' => $log->created_at,
            ];
        }), ['audit_available' => true]);
    }

    public function saveNotificationPreferences(PlatformUser $user, CoreWorkspace $workspace, array $data): MonitorMutationResult
    {
        $resolved = $this->context->resolve($user, $workspace);
        abort_unless($resolved !== null, 404);
        $this->context->mutate($user, $workspace, $resolved['workspace'], $resolved['user'],
            fn ($locked) => $this->savePreference->save($locked, $resolved['user'], (bool) ($data['digest_enabled'] ?? false)),
            requiresManagement: false);

        return new MonitorMutationResult(true, 'Notification preferences updated.');
    }

    public function writeExport(PlatformUser $user, CoreWorkspace $workspace, mixed $output): void
    {
        $resolved = $this->context->resolve($user, $workspace);
        abort_unless($resolved !== null, 404);
        abort_unless(User::query()->whereKey($resolved['user']->getKey())->whereNotNull('email_verified_at')->exists(), 403);
        Gate::forUser($resolved['user'])->authorize('update', $resolved['workspace']);
        $this->export->write($resolved['workspace'], $output, $resolved['user']);
    }
}
