<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Enums\AuditAction;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Account\AuditLogRequest;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\AuditStream;
use App\Models\BackupDestination;
use App\Models\User;
use App\Queries\Audit\AccountAuditLogQuery;
use App\Queries\Projects\ProjectSwitcherQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/account/audit-log?project=&person=&category=&from=&to=&cursor=`. */
final class ShowAuditLogController
{
    /**
     * Return a page of the account's audit log with the filters that chose it, what can filter it, and its streams.
     *
     * @param  Account  $account
     * @param  AuditLogRequest  $request
     * @param  User  $user
     * @param  AccountAuditLogQuery  $query
     * @param  ProjectSwitcherQuery  $projects
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, AuditLogRequest $request, #[CurrentUser] User $user, AccountAuditLogQuery $query, ProjectSwitcherQuery $projects): JsonResponse
    {
        $projectOptions = $projects->handle($account, 500);
        $members = $account->members()->orderBy('name')->get(['users.id', 'users.name']);
        $filters = $request->filters(array_column($projectOptions, 'id'), $members->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all());
        $entries = $query->handle($account, $filters);

        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'entries' => $entries->items(),
            'nextCursor' => $entries->nextCursor()?->encode(),
            'previousCursor' => $entries->previousCursor()?->encode(),
            'filters' => [
                'project' => $filters->projectId,
                'person' => $filters->actorId,
                'category' => $filters->category,
                'from' => $filters->from?->toDateString(),
                'to' => $filters->to?->toDateString(),
            ],
            'projects' => $projectOptions,
            'members' => $members->map(fn (User $member): array => ['id' => (string) $member->id, 'name' => $member->name])->values(),
            'categories' => array_map(fn (string $label): string => __($label), AuditAction::CATEGORIES),
            'retentionDays' => AuditEntry::RETENTION_DAYS,
            'streams' => AuditStream::query()->where('account_id', $account->id)->with('destination')->orderBy('name')->get()->map(fn (AuditStream $stream): array => [
                'id' => $stream->id,
                'name' => $stream->name,
                'type' => __(AuditStream::TYPES[$stream->type] ?? $stream->type),
                'destination' => $stream->destination?->name,
                'enabled' => (bool) $stream->enabled,
                'lastError' => $stream->last_error,
                'lastDeliveredAt' => $stream->last_delivered_at?->toIso8601String(),
            ])->values(),
            'streamTypes' => array_map(fn (string $label): string => __($label), AuditStream::TYPES),
            'backupDestinations' => BackupDestination::query()->where('account_id', $account->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (BackupDestination $destination): array => ['id' => $destination->id, 'name' => $destination->name])->values(),
            'canManageStreams' => $user->can('update', $account),
        ]);
    }
}
