<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Exceptions\StateConflict;
use App\Jobs\Infrastructure\RemoveWebsitePlacement;
use App\Models\Account;
use App\Models\Preview;
use App\Models\User;
use App\Models\Website;
use App\Services\Infrastructure\WebsiteHealthChecks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteWebsite
{
    /**
     * Create a new DeleteWebsite instance.
     *
     * Deletes a website and cleans it off its server.
     *
     * @param  WebsiteHealthChecks  $health  Removes its health monitor.
     * @param  RecordAuditEntry  $audit  Records it.
     */
    public function __construct(private readonly WebsiteHealthChecks $health, private readonly RecordAuditEntry $audit) {}

    /**
     * Delete a website: it disappears at once, and its files, Caddy site and database are removed from its servers in
     * the background. An open preview's website goes with its preview instead.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  Website  $website
     * @return void
     */
    public function handle(Account $account, User $actor, Website $website): void
    {
        DB::transaction(function () use ($account, $actor, $website): void {
            Gate::forUser($actor)->authorize('delete', $website);
            $locked = Website::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($website->id);
            StateConflict::unless(! Preview::query()->where('website_id', $locked->id)->where('status', '!=', Preview::STATUS_CLOSED)->exists(), __('This website belongs to an open preview. Close the preview instead.'));
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
