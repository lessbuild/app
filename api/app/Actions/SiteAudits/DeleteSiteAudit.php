<?php

declare(strict_types=1);

namespace App\Actions\SiteAudits;

use App\Models\SiteAudit;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class DeleteSiteAudit
{
    /**
     * Delete an audit with its runs, screenshots and mock-ups.
     *
     * @param  User  $actor
     * @param  SiteAudit  $audit
     * @return void
     */
    public function handle(User $actor, SiteAudit $audit): void
    {
        Gate::forUser($actor)->authorize('manageService', [$audit->project, 'audit']);
        foreach ($audit->runs()->get() as $run) {
            Storage::disk('local')->deleteDirectory($run->storageDirectory());
        }
        $audit->delete();
    }
}
