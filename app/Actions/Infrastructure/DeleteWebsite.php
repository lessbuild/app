<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Jobs\Infrastructure\RemoveWebsitePlacement;
use App\Models\Account;
use App\Models\User;
use App\Models\Website;
use App\Services\Infrastructure\WebsiteHealthChecks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteWebsite
{
    public function __construct(private readonly WebsiteHealthChecks $health, private readonly RecordAuditEntry $audit) {}

    /** Delete a website: it disappears at once, and its files, Caddy site and database are removed from its servers in the background. */
    public function handle(Account $account, User $actor, Website $website): void
    {
        DB::transaction(function () use ($account, $actor, $website): void {
            Gate::forUser($actor)->authorize('update', $account);
            $locked = Website::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($website->id);
            $locked->delete();
            $this->health->sync($locked, $actor);
            // The stale copy first, so a failure never takes down the live one before the deletion can finish.
            foreach (array_values(array_unique(array_filter([$locked->previous_server_id, $locked->server_id]))) as $serverId) {
                RemoveWebsitePlacement::dispatch($locked->id, $serverId, $locked->deployment_slug)->afterCommit();
            }
            $this->audit->handle(AuditAction::WebsiteDeleted, $actor, $account->id, ['website' => $locked->name, 'url' => $locked->url]);
        });
    }
}
