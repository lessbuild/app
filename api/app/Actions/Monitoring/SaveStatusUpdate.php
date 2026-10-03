<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Jobs\Monitoring\SendStatusUpdateToWebhooks;
use App\Models\StatusPage;
use App\Models\StatusSubscription;
use App\Models\StatusUpdate;
use App\Models\User;
use App\Notifications\StatusUpdateNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

final class SaveStatusUpdate
{
    /**
     * Create a new SaveStatusUpdate instance.
     *
     * Posts or edits an incident or maintenance update on a status page.
     *
     * @param  RecordAuditEntry  $audit  Records it.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Post or change an incident or maintenance notice, then email the page's confirmed subscribers (after the commit).
     *
     * @param  StatusPage  $page
     * @param  User  $actor
     * @param  array<string, mixed>  $data  validated by StatusUpdateRequest; times are UTC
     * @param  StatusUpdate|null  $update
     * @return StatusUpdate
     */
    public function handle(StatusPage $page, User $actor, array $data, ?StatusUpdate $update = null): StatusUpdate
    {
        $update = DB::transaction(function () use ($page, $actor, $data, $update): StatusUpdate {
            $page = StatusPage::query()->lockForUpdate()->findOrFail($page->id);
            Gate::forUser($actor)->authorize('update', $page);
            $isNew = $update === null;
            $update = $isNew ? new StatusUpdate : $page->updates()->lockForUpdate()->findOrFail($update->id);
            $closed = in_array($data['status'], ['resolved', 'completed'], true);
            $text = fn (string $key): ?string => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : null;

            $update->forceFill([
                'status_page_id' => $page->id,
                'created_by' => $update->created_by ?? $actor->id,
                'kind' => $data['kind'],
                'status' => $data['status'],
                'severity' => $data['severity'],
                'title' => trim((string) $data['title']),
                'message' => trim((string) $data['message']),
                'root_cause' => $text('root_cause'),
                'remediation' => $text('remediation'),
                'follow_up' => $text('follow_up'),
                'starts_at' => CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', (string) $data['starts_at'], 'UTC'),
                'ends_at' => $text('ends_at') !== null ? CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', (string) $data['ends_at'], 'UTC') : null,
                'resolved_at' => $closed ? ($update->resolved_at ?? CarbonImmutable::now('UTC')) : null,
            ])->save();

            $this->audit->handle($isNew ? AuditAction::StatusUpdatePosted : AuditAction::StatusUpdateChanged, $actor, $page->account_id, [
                'page' => $page->name, 'title' => $update->title, 'kind' => $update->kind, 'status' => $update->status,
            ]);

            return $update;
        }, attempts: 3);

        if ($page->published) {
            $page->subscriptions()->whereNotNull('verified_at')->lazyById(500)->each(function (StatusSubscription $subscription) use ($update): void {
                Notification::route('mail', $subscription->email)->notify(new StatusUpdateNotification($update, $subscription));
            });
            SendStatusUpdateToWebhooks::dispatch($update->id);
        }

        return $update;
    }
}
